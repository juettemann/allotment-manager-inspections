<?php
/**
 * AJAX endpoints for the field inspector PWA.
 *
 * Read-only endpoints that surface the data the SPA needs:
 *   - am_inspect_list_rounds — active rounds the inspector can work on
 *   - am_inspect_list_plots  — plots in scope for a given round
 *   - am_inspect_get_plot    — single plot detail + current finding (if any)
 *
 * Plus one mutating endpoint:
 *   - am_inspect_save_finding — records a finding via the main plugin's
 *     Inspection_Finding model, single-inspector (field-PWA flow). It exists
 *     because the committee admin form's `am_inspection_record_finding`
 *     endpoint has a different nonce action + required fields, so the PWA
 *     could never post to it. Photo upload still uses the main plugin's
 *     `am_inspection_upload_photo` (its nonce action matches).
 *
 * @package AllotmentManagerInspections
 */

namespace AllotmentManagerInspections;

defined( 'ABSPATH' ) || exit;

/**
 * AJAX endpoint registrar + handlers.
 */
final class Inspect_Ajax {

	/**
	 * Wire up the three actions.
	 *
	 * @return void
	 */
	public static function register(): void {
		\add_action( 'wp_ajax_am_inspect_list_rounds', [ self::class, 'list_rounds' ] );
		\add_action( 'wp_ajax_am_inspect_list_plots', [ self::class, 'list_plots' ] );
		\add_action( 'wp_ajax_am_inspect_get_plot', [ self::class, 'get_plot' ] );
		\add_action( 'wp_ajax_am_inspect_save_finding', [ self::class, 'save_finding' ] );
		\add_action( 'wp_ajax_am_inspect_update_finding', [ self::class, 'update_finding' ] );
	}

	/**
	 * Shared gate: verify nonce + capability. Returns true if the request may
	 * proceed; otherwise sends a JSON error and halts.
	 *
	 * @return void
	 */
	private static function authorise(): void {
		if ( ! \check_ajax_referer( 'am_inspect_nonce', 'nonce', false ) ) {
			\wp_send_json_error( [ 'message' => \__( 'Invalid security token. Please refresh and try again.', 'allotment-manager-inspections' ) ], 403 );
		}
		if ( ! \current_user_can( AMI_CAPABILITY ) ) {
			\wp_send_json_error( [ 'message' => \__( 'You do not have inspector permissions.', 'allotment-manager-inspections' ) ], 403 );
		}
	}

	/**
	 * POST action=am_inspect_save_finding
	 *
	 * Records a finding from the field PWA. The inspector's rating has already
	 * been translated client-side to a compliance category + status; we map it
	 * straight onto the main plugin's Inspection_Finding model, recording the
	 * LOGGED-IN user as the sole inspector. The committee's 2-inspector minimum
	 * is relaxed for this single-phone field flow via the
	 * `am_inspection_min_inspectors` filter.
	 *
	 * This replaces the old cross-plugin POST to `am_inspection_record_finding`
	 * (the committee admin form's endpoint), whose nonce action and required
	 * fields never matched what the PWA sends — so field findings never synced.
	 *
	 * @return void
	 */
	public static function save_finding(): void {
		self::authorise();

		$round_id  = isset( $_POST['round_id'] ) ? (int) $_POST['round_id'] : 0;
		$plot_id   = isset( $_POST['plot_id'] ) ? (int) $_POST['plot_id'] : 0;
		$member_id = isset( $_POST['member_id'] ) ? (int) $_POST['member_id'] : 0;
		if ( $round_id <= 0 || $plot_id <= 0 ) {
			\wp_send_json_error( [ 'message' => \__( 'Missing round or plot.', 'allotment-manager-inspections' ) ], 400 );
		}

		if ( ! \class_exists( '\AllotmentManager\Inspections\Inspection_Finding' ) ) {
			\wp_send_json_error( [ 'message' => \__( 'Inspections module unavailable.', 'allotment-manager-inspections' ) ], 500 );
		}

		$category = isset( $_POST['compliance_category'] ) ? \sanitize_key( $_POST['compliance_category'] ) : '';
		$status   = isset( $_POST['compliance_status'] ) ? \sanitize_key( $_POST['compliance_status'] ) : '';
		$notes    = isset( $_POST['findings_summary'] ) ? \sanitize_textarea_field( \wp_unslash( $_POST['findings_summary'] ) ) : '';
		$date     = ( isset( $_POST['inspection_date'] ) && \preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $_POST['inspection_date'] ) )
			? (string) $_POST['inspection_date']
			: \current_time( 'Y-m-d' );

		$data = [
			'round_id'            => $round_id,
			'plot_id'             => $plot_id,
			'member_id'           => $member_id,
			'inspection_date'     => $date,
			'compliance_status'   => $status,
			'compliance_category' => '' !== $category ? $category : null,
			'findings_summary'    => $notes,
			// Single field inspector = the logged-in user.
			'inspector_user_ids'  => [ \get_current_user_id() ],
		];

		// Issue tickboxes — forward only keys actually sent so the schema's
		// tri-state (NULL = "not assessed") is preserved when a key is omitted.
		foreach ( [ 'has_rubbish', 'has_overgrown_weeds', 'has_uncultivated_areas', 'has_derelict_structures', 'has_tenancy_breach' ] as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$data[ $key ] = ! empty( $_POST[ $key ] ) ? 1 : 0;
			}
		}
		if ( ! empty( $_POST['tenancy_breach_description'] ) ) {
			$data['tenancy_breach_description'] = \sanitize_text_field( \wp_unslash( $_POST['tenancy_breach_description'] ) );
		}

		// Committee-only note attached to a manual exemption / internal review.
		// The main plugin keeps it off the member portal; here it just rides
		// through. wp_unslash first so an apostrophe isn't stored as \'.
		if ( isset( $_POST['committee_notes'] ) ) {
			$data['committee_notes'] = \sanitize_textarea_field( \wp_unslash( $_POST['committee_notes'] ) );
		}

		// A field inspector often records just a rating (e.g. "Pass") with no
		// typed notes — but Inspection_Finding::create_finding() requires a
		// non-empty findings_summary, so those silently failed to sync. When no
		// summary was typed, synthesise a meaningful one from the rating + any
		// ticked issues so a rating-only finding still saves and reads sensibly.
		if ( '' === $notes ) {
			$data['findings_summary'] = self::auto_summary( $category, $data, $status );
		}

		// Relax the committee's 2-inspector minimum for this single-phone call,
		// then create via the main plugin's model (keeps its validation, the
		// UNIQUE round_plot guard, exemption + multi-plot logic, etc.).
		// try/finally so a throw inside create_finding can't leave the filter
		// registered for later calls in the same process (tests / CLI).
		$relax = static fn() => 1;
		\add_filter( 'am_inspection_min_inspectors', $relax );
		try {
			$result = \AllotmentManager\Inspections\Inspection_Finding::create_finding( $data );
		} finally {
			\remove_filter( 'am_inspection_min_inspectors', $relax );
		}

		if ( \is_wp_error( $result ) ) {
			\wp_send_json_error(
				[ 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ],
				400
			);
		}

		\wp_send_json_success( [ 'finding_id' => (int) $result, 'id' => (int) $result ] );
	}

	/**
	 * POST action=am_inspect_update_finding
	 *
	 * Edits an EXISTING finding (to correct a mistake). Allowed for one of the
	 * finding's own recorded inspectors, or for chair/admin (the override —
	 * `edit_any_inspection_finding` cap / manage_options). Maps the payload
	 * exactly like save_finding and forwards to the main plugin's
	 * Inspection_Finding::update_finding(), which audit-logs the change and
	 * keeps the recorded inspector(s) immutable.
	 *
	 * @return void
	 */
	public static function update_finding(): void {
		self::authorise();

		$finding_id = isset( $_POST['finding_id'] ) ? (int) $_POST['finding_id'] : 0;
		if ( $finding_id <= 0 ) {
			\wp_send_json_error( [ 'message' => \__( 'Missing finding.', 'allotment-manager-inspections' ) ], 400 );
		}
		if ( ! \class_exists( '\AllotmentManager\Inspections\Inspection_Finding' ) ) {
			\wp_send_json_error( [ 'message' => \__( 'Inspections module unavailable.', 'allotment-manager-inspections' ) ], 500 );
		}

		// Authorise the edit: a recorded inspector on THIS finding, or the
		// chair/admin override. (The model enforces the baseline record cap.)
		global $wpdb;
		$findings_table = $wpdb->prefix . 'am_inspection_findings';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT inspector_user_ids, compliance_category, compliance_status, has_rubbish, has_overgrown_weeds, has_uncultivated_areas, has_derelict_structures, has_tenancy_breach FROM {$findings_table} WHERE id = %d", $finding_id ) );
		if ( ! $row ) {
			\wp_send_json_error( [ 'message' => \__( 'Finding not found.', 'allotment-manager-inspections' ) ], 404 );
		}
		$inspector_ids = ! empty( $row->inspector_user_ids ) ? array_map( 'intval', (array) json_decode( (string) $row->inspector_user_ids, true ) ) : [];
		$is_own      = \in_array( \get_current_user_id(), $inspector_ids, true );
		$is_override = \current_user_can( 'edit_any_inspection_finding' ) || \current_user_can( 'manage_options' );
		if ( ! $is_own && ! $is_override ) {
			\wp_send_json_error( [ 'message' => \__( 'You can only edit your own findings. Ask the chair to change another inspector’s finding.', 'allotment-manager-inspections' ) ], 403 );
		}

		$category = isset( $_POST['compliance_category'] ) ? \sanitize_key( $_POST['compliance_category'] ) : '';
		$status   = isset( $_POST['compliance_status'] ) ? \sanitize_key( $_POST['compliance_status'] ) : '';
		$notes    = isset( $_POST['findings_summary'] ) ? \sanitize_textarea_field( \wp_unslash( $_POST['findings_summary'] ) ) : '';

		$data = [ 'findings_summary' => $notes ];
		if ( '' !== $category ) {
			$data['compliance_category'] = $category;
		}
		if ( '' !== $status ) {
			$data['compliance_status'] = $status;
		}
		foreach ( [ 'has_rubbish', 'has_overgrown_weeds', 'has_uncultivated_areas', 'has_derelict_structures', 'has_tenancy_breach' ] as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$data[ $key ] = ! empty( $_POST[ $key ] ) ? 1 : 0;
			}
		}
		if ( isset( $_POST['tenancy_breach_description'] ) ) {
			$data['tenancy_breach_description'] = \sanitize_text_field( \wp_unslash( $_POST['tenancy_breach_description'] ) );
		}
		if ( isset( $_POST['committee_notes'] ) ) {
			$data['committee_notes'] = \sanitize_textarea_field( \wp_unslash( $_POST['committee_notes'] ) );
		}

		// Stale auto-summary guard. The editor pre-fills the notes box with the
		// existing summary, so an inspector who changes only the RATING (and
		// leaves the notes) would otherwise keep a summary that now contradicts
		// the new verdict (e.g. category_2 reading "Pass — no issues recorded.").
		// If the submitted notes are EXACTLY the auto-summary the CURRENT stored
		// verdict would produce, the inspector never hand-typed them — clear so
		// the block below regenerates one for the NEW verdict. Hand-written notes
		// (which won't match) are preserved untouched.
		if ( '' !== $notes ) {
			$before_issue = [];
			foreach ( [ 'has_rubbish', 'has_overgrown_weeds', 'has_uncultivated_areas', 'has_derelict_structures', 'has_tenancy_breach' ] as $bk ) {
				$before_issue[ $bk ] = ! empty( $row->$bk ) ? 1 : 0;
			}
			if ( $notes === self::auto_summary( (string) ( $row->compliance_category ?? '' ), $before_issue, (string) ( $row->compliance_status ?? '' ) ) ) {
				$notes = '';
			}
		}
		if ( '' === $notes ) {
			$data['findings_summary'] = self::auto_summary( $category, $data, $status );
		}

		$result = \AllotmentManager\Inspections\Inspection_Finding::update_finding( $finding_id, $data );
		if ( \is_wp_error( $result ) ) {
			\wp_send_json_error( [ 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ], 400 );
		}

		\wp_send_json_success( [ 'finding_id' => $finding_id, 'id' => $finding_id, 'updated' => true ] );
	}

	/**
	 * Synthesise a findings summary from the rating + ticked issues when the
	 * inspector typed none — a rating-only verdict still needs a non-empty
	 * summary for Inspection_Finding. Shared by save_finding + update_finding.
	 *
	 * @param string $category Compliance category (category_1|2|3).
	 * @param array  $data     Update data carrying the has_* issue flags.
	 * @return string
	 */
	private static function auto_summary( string $category, array $data, string $status = '' ): string {
		$issue_labels = [
			'has_rubbish'             => \__( 'non-compostable rubbish', 'allotment-manager-inspections' ),
			'has_overgrown_weeds'     => \__( 'overgrown weeds', 'allotment-manager-inspections' ),
			'has_uncultivated_areas'  => \__( 'essentially no cultivation visible', 'allotment-manager-inspections' ),
			'has_derelict_structures' => \__( 'derelict structures', 'allotment-manager-inspections' ),
			'has_tenancy_breach'      => \__( 'tenancy agreement breach', 'allotment-manager-inspections' ),
		];
		$ticked = [];
		foreach ( $issue_labels as $issue_key => $issue_label ) {
			if ( ! empty( $data[ $issue_key ] ) ) {
				$ticked[] = $issue_label;
			}
		}
		$base = [
			'category_1' => \__( 'Pass — no issues recorded.', 'allotment-manager-inspections' ),
			'category_2' => \__( 'Minor corrections needed.', 'allotment-manager-inspections' ),
			'category_3' => \__( 'Major issues — action required.', 'allotment-manager-inspections' ),
		];
		// Exemption / internal-review findings carry no category, so fall back
		// to a status-specific line rather than the generic default.
		$status_base = [
			'exempt'          => \__( 'Plot exempt this round.', 'allotment-manager-inspections' ),
			'internal_review' => \__( 'Referred for committee review.', 'allotment-manager-inspections' ),
		];
		$summary = $base[ $category ] ?? $status_base[ $status ] ?? \__( 'Inspection recorded.', 'allotment-manager-inspections' );
		if ( $ticked ) {
			$summary .= ' ' . \sprintf(
				/* translators: %s: comma-separated list of ticked issues */
				\__( 'Issues observed: %s.', 'allotment-manager-inspections' ),
				\implode( ', ', $ticked )
			);
		}
		return $summary;
	}

	/**
	 * GET /wp-admin/admin-ajax.php?action=am_inspect_list_rounds
	 *
	 * @return void
	 */
	public static function list_rounds(): void {
		self::authorise();

		global $wpdb;

		$rounds_table   = $wpdb->prefix . 'am_inspection_rounds';
		$findings_table = $wpdb->prefix . 'am_inspection_findings';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT
				r.id,
				r.round_number,
				r.site_section,
				r.status,
				r.scheduled_start_date,
				r.scheduled_end_date,
				r.total_plots_count,
				r.inspected_plots_count,
				(SELECT COUNT(*) FROM {$findings_table} f WHERE f.round_id = r.id) AS findings_count
			FROM {$rounds_table} r
			WHERE r.status IN ('scheduled', 'in_progress')
			ORDER BY r.scheduled_start_date DESC, r.id DESC"
		);
		// phpcs:enable

		$rounds = array_map(
			static function ( $row ) {
				return [
					'id'                  => (int) $row->id,
					'roundNumber'         => $row->round_number,
					'siteSection'         => $row->site_section,
					'status'              => $row->status,
					'scheduledStartDate'  => $row->scheduled_start_date,
					'scheduledEndDate'    => $row->scheduled_end_date,
					'totalPlots'          => (int) $row->total_plots_count,
					'inspectedPlots'      => (int) max( (int) $row->inspected_plots_count, (int) $row->findings_count ),
				];
			},
			$rows ?: []
		);

		\wp_send_json_success( [ 'rounds' => $rounds ] );
	}

	/**
	 * GET ?action=am_inspect_list_plots&round_id=N
	 *
	 * For a primary round: all plots in r.site_section.
	 * For a followup round: only the plots the parent round flagged for
	 * re-inspection — see {@see fetch_plot_rows()}.
	 *
	 * @return void
	 */
	public static function list_plots(): void {
		self::authorise();

		$round_id = isset( $_GET['round_id'] ) ? (int) $_GET['round_id'] : 0;
		if ( $round_id <= 0 ) {
			\wp_send_json_error( [ 'message' => \__( 'Missing round_id.', 'allotment-manager-inspections' ) ], 400 );
		}

		global $wpdb;
		$rounds_table = $wpdb->prefix . 'am_inspection_rounds';

		// Load round meta.
		$round = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, round_number, site_section, status FROM {$rounds_table} WHERE id = %d", $round_id )
		);
		if ( ! $round ) {
			\wp_send_json_error( [ 'message' => \__( 'Round not found.', 'allotment-manager-inspections' ) ], 404 );
		}


		$rows = self::fetch_plot_rows( $round );

		$plots = array_map( [ self::class, 'format_plot_row' ], $rows );

		$tile = self::tile_config();

		\wp_send_json_success(
			[
				'round' => [
					'id'             => (int) $round->id,
					'roundNumber'    => $round->round_number,
					'siteSection'    => $round->site_section,
					'status'         => $round->status,
				],
				'plots' => $plots,
				'map'   => [ 'tile' => $tile ],
			]
		);
	}

	/**
	 * ORDER BY fragment that sorts plot numbers the way an inspector walks them.
	 *
	 * The list was ordered by `LENGTH(plot_number), plot_number`, which buckets
	 * by how many characters the number has and only then compares text. So on
	 * the live 2026 Vinery round the inspector was handed V1…V97 followed by
	 * every subdivided plot — V3.1, V3.2, V15.1, V83.2 — because "V3.1" is four
	 * characters and "V97" is three. The halves were in the right order relative
	 * to each other, and in completely the wrong place on the round (#42).
	 *
	 * A plot number is a letter prefix, a whole number, and optionally a
	 * subdivision after a dot. So it sorts on those three keys, the numbers
	 * compared as numbers:
	 *
	 *   V1, V2, V3, V3.1, V3.2, V4 … V9, V10 … V97
	 *
	 * A missing subdivision counts as 0, so an undivided plot leads its own
	 * halves. This mirrors `am_plot_number_order_sql()` in the main plugin,
	 * which is the same rule for the admin lists — the inspector and the
	 * committee should not see two different orders for one section.
	 *
	 * SUBSTRING_INDEX rather than a lookbehind regex: both hosts run MariaDB
	 * 10.11 while CI runs MySQL 8, and they differ on REGEXP_SUBSTR lookbehind.
	 *
	 * @since #42
	 * @param string $column Fully-qualified column. Code-supplied, never user input.
	 * @return string SQL fragment for an ORDER BY list.
	 */
	private static function plot_number_order_sql( string $column = 'p.plot_number' ): string {
		$numeric_part = "REGEXP_REPLACE({$column}, '^[^0-9]+', '')";

		return "REGEXP_REPLACE({$column}, '[^A-Za-z].*$', '') ASC, "
			. "CAST(SUBSTRING_INDEX({$numeric_part}, '.', 1) AS UNSIGNED) ASC, "
			. "CAST(IF({$column} LIKE '%.%', SUBSTRING_INDEX({$column}, '.', -1), '0') AS UNSIGNED) ASC";
	}


	/**
	 * The FIRST round's finding for this plot, with its photographs.
	 *
	 * A follow-up exists to check that required work was done, which the
	 * inspector cannot judge without knowing what was wrong. The plot list
	 * carried only the previous CATEGORY ("Cat 3"), which says how bad it was,
	 * not what to look at — and the detail screen carried nothing at all, so an
	 * inspector standing on the plot had to remember or ring someone (#43).
	 *
	 * So this returns what the work order actually was: the summary the member
	 * was told, the specific issues ticked, and the photographs taken at the
	 * time. The photos are the useful part on site — a before-and-after against
	 * what they are looking at.
	 *
	 * Returns null when the plot has no first visit in this round — which is
	 * every plot until it is inspected once.
	 *
	 * @since #43
	 * @param int $round_id The CURRENT round.
	 * @param int $plot_id  Plot being opened.
	 * @return array<string,mixed>|null Previous finding for the API response.
	 */
	private static function previous_finding( int $round_id, int $plot_id ): ?array {
		global $wpdb;

		// The work order is now visit 1 of THIS round, not a finding in a
		// separate parent round (#883). Returns null when the plot has no first
		// visit yet, which is the ordinary case for an initial inspection.
		$findings_table = $wpdb->prefix . 'am_inspection_findings';

		$prev = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, compliance_category, compliance_status, findings_summary,
					has_rubbish, has_overgrown_weeds, has_uncultivated_areas,
					has_derelict_structures, has_tenancy_breach, tenancy_breach_description,
					cultivation_percentage, inspection_date, inspector_names, voided_at
				FROM {$findings_table}
				WHERE plot_id = %d AND round_id = %d AND visit_sequence = 1
				ORDER BY id ASC
				LIMIT 1",
				$plot_id,
				$round_id
			)
		);

		if ( ! $prev ) {
			return null;
		}

		return [
			'id'                 => (int) $prev->id,
			'category'           => $prev->compliance_category,
			'status'             => $prev->compliance_status,
			'summary'            => $prev->findings_summary,
			'inspectionDate'     => $prev->inspection_date,
			'recordedBy'         => $prev->inspector_names ?: null,
			'cultivationPercent' => null !== $prev->cultivation_percentage ? (float) $prev->cultivation_percentage : null,
			// The specific issues that had to be put right. This IS the work
			// order, so it is what the follow-up is checking against.
			'issues'             => [
				'rubbish'           => (bool) $prev->has_rubbish,
				'overgrownWeeds'    => (bool) $prev->has_overgrown_weeds,
				'uncultivatedAreas' => (bool) $prev->has_uncultivated_areas,
				'derelictStructures'=> (bool) $prev->has_derelict_structures,
				'tenancyBreach'     => (bool) $prev->has_tenancy_breach,
			],
			'tenancyBreachDescription' => $prev->tenancy_breach_description ?: null,
			// Voided means the membership ended mid-round. The plot is then out of
			// scope, but if it is somehow being viewed the state must be visible
			// rather than presented as a live work order.
			'isVoided'           => ! empty( $prev->voided_at ),
			'photos'             => self::finding_photos( (int) $prev->id ),
		];
	}

	/**
	 * A finding's photographs, oldest first.
	 *
	 * Extracted so the current finding and the previous one are shaped
	 * identically for the app (#43).
	 *
	 * @since #43
	 * @param int $finding_id Finding id.
	 * @return array<int,array<string,mixed>> Photo rows.
	 */
	private static function finding_photos( int $finding_id ): array {
		global $wpdb;

		$photos_table = $wpdb->prefix . 'am_inspection_photos';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, google_drive_url, google_drive_thumbnail_url, photo_caption, photo_order
				FROM {$photos_table}
				WHERE finding_id = %d AND deleted_at IS NULL
				ORDER BY photo_order ASC, id ASC",
				$finding_id
			)
		);

		return array_map(
			static fn( $p ) => [
				'id'           => (int) $p->id,
				'url'          => $p->google_drive_url,
				'thumbnailUrl' => $p->google_drive_thumbnail_url,
				'caption'      => $p->photo_caption,
				'order'        => (int) $p->photo_order,
			],
			$rows ?: []
		);
	}

	/**
	 * Every plot in the round's section.
	 *
	 * A round is one per (year, site) and covers its whole section (#883), so
	 * there is no subset to select: a re-inspection is visit 2 within this same
	 * round, not a round of its own. The follow-up branch that selected only the
	 * flagged plots — and the `in_scope` flag the app faded the rest with — are
	 * gone with it.
	 *
	 * Worth keeping from that branch, because it is a live trap on this table:
	 * "did this plot fail?" is `requires_followup = 1` on a non-voided finding,
	 * NOT `compliance_category IN ('category_2', 'category_3')`. Category
	 * measures CULTIVATION and is independent of compliance — a plot failed for
	 * rubbish, derelict structures or a tenancy breach while well cultivated is
	 * Category 1 — and it is nullable besides. Selecting on it silently dropped
	 * a plot from its own follow-up in the live 2026 round (#39).
	 *
	 * Each finding join resolves to ONE row (`id = (SELECT MAX/MIN ...)`). A
	 * plain (plot_id, round_id) match is one-to-many, which duplicates the PLOT
	 * ROW rather than adding columns — a subdivided plot legitimately holds one
	 * finding per subdivision in a round, and the inspector saw it listed twice
	 * (#44).
	 *
	 * Ordering is {@see plot_number_order_sql()}, NOT the list's original
	 * `LENGTH(plot_number), plot_number`. See that method for why.
	 *
	 * @since #39
	 * @param object $round Round row: id, site_section.
	 * @return array<int,object> Plot rows for format_plot_row().
	 */
	private static function fetch_plot_rows( object $round ): array {
		global $wpdb;
		$plots_table    = $wpdb->prefix . 'am_plots';
		$findings_table = $wpdb->prefix . 'am_inspection_findings';
		$map_obj_table  = $wpdb->prefix . 'am_map_objects';

		// Resolve each plot's current holder from the active tenancy assignment
		// (rather than the stale-prone current_member_id). Shared with get_plot so
		// the list and the detail view always resolve holders identically — see
		// holder_join_sql() for the full rationale. Exposes `asg` + members alias `m`.
		$holder_join = self::holder_join_sql();

		$round_id = (int) $round->id;

			$sql = $wpdb->prepare(
				"SELECT
					p.id,
					p.plot_number,
					p.section,
					COALESCE(asg.member_id, p.current_member_id) AS effective_member_id,
					asg.start_date AS assignment_start_date,
					m.user_id AS holder_user_id,
					m.first_name,
					m.last_name,
					mo.latitude,
					mo.longitude,
					mo.width,
					mo.height,
					mo.rotation_angle,
					curr.id AS current_finding_id,
					curr.compliance_category AS current_category,
					curr.compliance_status AS current_status,
					prev.compliance_category AS previous_category
				FROM {$plots_table} p
				{$holder_join}
				LEFT JOIN {$map_obj_table} mo ON mo.plot_id = p.id AND mo.object_type = 'plot'
				LEFT JOIN {$findings_table} curr
					ON curr.plot_id = p.id
					AND curr.id = (SELECT MAX(c2.id) FROM {$findings_table} c2
					                WHERE c2.plot_id = p.id AND c2.round_id = %d)
				LEFT JOIN {$findings_table} prev
					ON prev.plot_id = p.id
					AND prev.id = (SELECT MIN(p2.id) FROM {$findings_table} p2
					                WHERE p2.plot_id = p.id
					                  AND p2.round_id = %d
					                  AND p2.visit_sequence = 1
					                  AND p2.voided_at IS NULL)
				WHERE p.section = %s
				  AND (p.deleted_at IS NULL)
				ORDER BY " . self::plot_number_order_sql(),
				$round_id,
				$round_id,
				$round->site_section
			);
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql );
		// phpcs:enable

		return $rows ?: [];
	}

	/**
	 * Map a plots query row to the SPA's plot shape, including the map centroid.
	 *
	 * @param object $row Row from the list_plots query.
	 * @return array<string,mixed>
	 */
	private static function format_plot_row( $row ): array {
		$first = trim( (string) ( $row->first_name ?? '' ) );
		$last  = trim( (string) ( $row->last_name ?? '' ) );
		$name  = trim( $first . ' ' . $last );

		// Effective holder: prefer the SQL-resolved effective_member_id
		// (active-assignment member ?? current_member_id). Fall back to
		// current_member_id for older-shaped rows (e.g. unit-test fixtures that
		// don't carry the resolved column). 0 = genuinely vacant.
		$member_id = ! empty( $row->effective_member_id )
			? (int) $row->effective_member_id
			: ( ! empty( $row->current_member_id ) ? (int) $row->current_member_id : 0 );

		$start_date = ! empty( $row->assignment_start_date ) ? (string) $row->assignment_start_date : null;

		return [
			'id'                => (int) $row->id,
			'plotNumber'        => $row->plot_number,
			'section'           => $row->section,
			'memberId'          => $member_id ?: null,
			'tenantName'        => '' !== $name ? $name : null,
			// Occupancy state for the field UI: a vacant plot is shown but not
			// inspectable (recording would fail — create_finding requires a
			// member); a new tenant is shown flagged "exempt" (the server
			// auto-exempts them, so it's recordable but no notice is issued).
			'isVacant'          => 0 === $member_id,
			// The plot is the LOGGED-IN inspector's own: the server's
			// self-inspection guard (Inspection_Finding::create_finding) will
			// reject a finding on it, so the app must block recording up front
			// rather than let it fail and stick in the sync queue.
			'isOwnPlot'         => self::holder_is_current_user( $row ),
			'isNewTenant'       => self::is_new_tenant( $member_id, $start_date ),
			'tenantStartDate'   => $start_date,
			'currentFindingId'  => $row->current_finding_id ? (int) $row->current_finding_id : null,
			'currentCategory'   => $row->current_category,   // category_1|2|3 or null
			// The verdict the committee's own screens filter and badge by.
			// Category measures CULTIVATION and is NULL on an exempt or
			// under-review finding, so a list that reads only the category
			// cannot tell "not inspected" from "inspected and exempted", and
			// cannot answer "show me the non-compliant plots" at all — the two
			// axes are independent (see fetch_plot_rows()). Null when the plot
			// has no finding in this round.
			'currentStatus'     => $row->current_status ?? null,
			// Visit 1 of THIS round — the work order a re-inspection is against.
			'previousCategory'  => $row->previous_category,
			// Plot footprint from the admin Map Editor (wp_am_map_objects): the
			// centroid plus the box width/height (pixels at zoom 19) and rotation
			// (degrees). The map draws the plot's real rotated rectangle from
			// these so it scales with the satellite imagery instead of a fixed
			// dot. All null when the plot hasn't been positioned yet — the Map
			// view then falls back to its "set up in Map Editor" empty state.
			'lat'               => null === $row->latitude ? null : (float) $row->latitude,
			'lng'               => null === $row->longitude ? null : (float) $row->longitude,
			'width'             => null === ( $row->width ?? null ) ? null : (int) $row->width,
			'height'            => null === ( $row->height ?? null ) ? null : (int) $row->height,
			'rotation'          => null === ( $row->rotation_angle ?? null ) ? null : (float) $row->rotation_angle,
		];
	}

	/**
	 * SQL JOIN fragment that resolves a plot's CURRENT holder from the active
	 * tenancy assignment — the authoritative tenancy record — rather than the
	 * denormalised wp_am_plots.current_member_id, which goes stale when a plot is
	 * reassigned (left NULL, or still pointing at the departed tenant: the known
	 * "orphaned-allocated" gap, cf. `wp am resync_plot_holders`).
	 *
	 * The derived table returns one row per plot — the member id + start_date of
	 * the most recent active, non-deleted assignment (GROUP_CONCAT/SUBSTRING_INDEX
	 * picks the latest by start_date, MAX(start_date) is its date). The member
	 * join then PREFERS the active assignment and falls back to current_member_id
	 * only when there's no active assignment, so the resolved name + start_date
	 * always come from the same record. A plot with neither is genuinely vacant.
	 *
	 * Assumes the outer query aliases the plots table `p`. Exposes `asg.member_id`,
	 * `asg.start_date`, and the members alias `m`. Carries no `%` placeholders so
	 * it interpolates safely into a prepared statement. Shared by list_plots +
	 * get_plot so the two can never resolve a plot's holder differently.
	 *
	 * @return string
	 */
	private static function holder_join_sql(): string {
		global $wpdb;
		$assign_table  = $wpdb->prefix . 'am_plot_assignments';
		$members_table = $wpdb->prefix . 'mm_members';
		return "LEFT JOIN (
				SELECT plot_id,
					CAST(SUBSTRING_INDEX(GROUP_CONCAT(member_id ORDER BY start_date DESC, id DESC), ',', 1) AS UNSIGNED) AS member_id,
					MAX(start_date) AS start_date
				FROM {$assign_table}
				WHERE status = 'active' AND deleted_at IS NULL
				GROUP BY plot_id
			) asg ON asg.plot_id = p.id
			LEFT JOIN {$members_table} m ON m.id = COALESCE(asg.member_id, p.current_member_id)";
	}

	/**
	 * Whether a plot's current holder is a "new tenant" — started after the
	 * 1 March cutoff and therefore exempt from compliance notices this round.
	 *
	 * UI HINT ONLY. The authoritative exemption runs server-side in
	 * create_finding() (Inspection_Finding::check_new_tenant_exemption, keyed off
	 * the round's inspection DATE year), so a slightly stale badge never changes
	 * the recorded verdict. This badge approximates that with the CURRENT calendar
	 * year — exact for an in-season round, and good enough for a hint where the
	 * round's inspection date isn't loaded here. The month/day (1 March) tracks
	 * Inspection_Finding::NEW_TENANT_CUTOFF — keep them in step if that policy
	 * date ever changes.
	 *
	 * @param int         $member_id  Effective member id (0 = vacant).
	 * @param string|null $start_date Active assignment start date (Y-m-d) or null.
	 * @return bool
	 */
	private static function is_new_tenant( int $member_id, ?string $start_date ): bool {
		if ( $member_id <= 0 || empty( $start_date ) ) {
			return false;
		}
		$cutoff = \current_time( 'Y' ) . '-03-01';
		return $start_date > $cutoff;
	}

	/**
	 * Whether the plot's current holder is the logged-in inspector — i.e. it's
	 * their OWN plot. Mirrors the server-side self-inspection guard in
	 * Inspection_Finding::create_finding (which compares the member's linked WP
	 * user id against the inspector), so the field UI can refuse to record it up
	 * front instead of queuing a finding the server will always reject. Reads the
	 * resolved holder's WP user id (`holder_user_id`, from the members join); 0 /
	 * absent when the plot is vacant or the member has no linked WP account.
	 *
	 * @param object $row Row carrying `holder_user_id` from the holder join.
	 * @return bool
	 */
	private static function holder_is_current_user( $row ): bool {
		$holder_uid  = ! empty( $row->holder_user_id ) ? (int) $row->holder_user_id : 0;
		$current_uid = \get_current_user_id();
		return $holder_uid > 0 && $current_uid > 0 && $holder_uid === $current_uid;
	}

	/**
	 * Tile-layer config for the Map view. Resolved via the same
	 * `am_map_tile_layer` filter the main plugin's maps use, so an admin's
	 * paid-tile-provider override applies here too. Shape mirrors what
	 * member-map-view.js consumes (url/attribution/maxZoom/subdomains).
	 *
	 * @return array<string,mixed>
	 */
	private static function tile_config(): array {
		return \apply_filters(
			'am_map_tile_layer',
			[
				'url'           => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
				'attribution'   => '© OpenStreetMap contributors',
				'maxNativeZoom' => 19,
				'maxZoom'       => 19,
				'subdomains'    => [ 'a', 'b', 'c' ],
			]
		);
	}

	/**
	 * The most recent visit recorded on this plot in this round, if any.
	 *
	 * The one the editor opens for correction. This was `LIMIT 1` with no
	 * ordering, over a table that has held one row per VISIT since #883 — so on
	 * a plot inspected twice the database was free to return either, and in
	 * practice returned the primary. An inspector correcting the re-inspection
	 * would silently have edited the original instead: both rows carry the same
	 * plot, round and tenant, and nothing on the screen named which was which.
	 *
	 * @since #50
	 *
	 * @param int $round_id Round ID.
	 * @param int $plot_id  Plot ID.
	 * @return object|null Finding row, or null when the plot is uninspected.
	 */
	public static function latest_finding( int $round_id, int $plot_id ): ?object {
		global $wpdb;
		$findings_table = $wpdb->prefix . 'am_inspection_findings';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					id, visit_sequence, voided_at,
					compliance_category, compliance_status, findings_summary, committee_notes, requires_followup,
					has_rubbish, has_overgrown_weeds, has_uncultivated_areas,
					has_derelict_structures, has_tenancy_breach, tenancy_breach_description,
					inspector_user_ids, inspector_names, created_at, updated_at
				FROM {$findings_table}
				WHERE plot_id = %d AND round_id = %d
				ORDER BY visit_sequence DESC, id DESC
				LIMIT 1",
				$plot_id,
				$round_id
			)
		);

		return $row ?: null;
	}

	/**
	 * Whether a re-inspection can be recorded on this plot right now.
	 *
	 * Until #50 the app could not record one at all. A plot that already had a
	 * finding opened for EDIT and nothing else, so an inspector doing the
	 * re-inspection in the field would have overwritten the primary result — the
	 * record the notice was served on — while this same screen showed them the
	 * work order and invited them to record against it. The server always
	 * accepted the second visit; only the way in was missing.
	 *
	 * Visit 1 only. Inspection_Finding::create_finding() infers the visit and
	 * caps that inference at 2, so a third comes back as `duplicate_finding` —
	 * offering it would be a dead end. A voided finding means the tenancy ended
	 * mid-round and the plot is out of scope. The vacant and own-plot cases are
	 * the same bars the editor already applies to a first finding, and a
	 * follow-up is a new finding.
	 *
	 * @since #50
	 *
	 * @param object|null $finding     Latest visit, or null when uninspected.
	 * @param int         $member_id   Effective holder, 0 when vacant.
	 * @param bool        $is_own_plot Whether the viewer holds this plot.
	 * @return bool
	 */
	public static function follow_up_available( ?object $finding, int $member_id, bool $is_own_plot ): bool {
		if ( null === $finding || $member_id <= 0 || $is_own_plot ) {
			return false;
		}

		if ( ! empty( $finding->voided_at ) ) {
			return false;
		}

		return 1 === (int) $finding->visit_sequence;
	}

	/**
	 * GET ?action=am_inspect_get_plot&plot_id=N&round_id=M
	 *
	 * Returns a single plot's detail + the latest finding recorded in this round
	 * + the photos attached to that finding, and whether a re-inspection can be
	 * recorded here.
	 *
	 * @return void
	 */
	public static function get_plot(): void {
		self::authorise();

		$plot_id  = isset( $_GET['plot_id'] ) ? (int) $_GET['plot_id'] : 0;
		$round_id = isset( $_GET['round_id'] ) ? (int) $_GET['round_id'] : 0;
		if ( $plot_id <= 0 || $round_id <= 0 ) {
			\wp_send_json_error( [ 'message' => \__( 'Missing plot_id or round_id.', 'allotment-manager-inspections' ) ], 400 );
		}

		global $wpdb;
		$plots_table    = $wpdb->prefix . 'am_plots';
		$findings_table = $wpdb->prefix . 'am_inspection_findings';

		// Resolve the holder from the active assignment (see holder_join_sql) so a
		// freshly-assigned tenant shows by name and the correct member_id flows
		// into save_finding. Same join as list_plots — single source of truth.
		$holder_join = self::holder_join_sql();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					p.id,
					p.plot_number,
					p.section,
					COALESCE(asg.member_id, p.current_member_id) AS effective_member_id,
					asg.start_date AS assignment_start_date,
					m.user_id AS holder_user_id,
					m.first_name,
					m.last_name,
					m.email,
					m.membership_number
				FROM {$plots_table} p
				{$holder_join}
				WHERE p.id = %d AND (p.deleted_at IS NULL)",
				$plot_id
			)
		);
		if ( ! $row ) {
			\wp_send_json_error( [ 'message' => \__( 'Plot not found.', 'allotment-manager-inspections' ) ], 404 );
		}

		$finding = self::latest_finding( $round_id, $plot_id );

		$photos = $finding ? self::finding_photos( (int) $finding->id ) : [];

		// Edit policy + warn metadata for an existing finding. A finding may be
		// edited by one of its recorded inspectors, or by chair/admin (the
		// override). `hasNotice` lets the app WARN before changing a finding a
		// notice was already sent for; `edited` flags a previously-corrected one.
		$can_edit    = false;
		$recorded_by = null;
		$has_notice  = false;
		$edited      = false;
		$edited_at   = null;
		if ( $finding ) {
			$inspector_ids = ! empty( $finding->inspector_user_ids ) ? (array) json_decode( (string) $finding->inspector_user_ids, true ) : [];
			$inspector_ids = array_map( 'intval', $inspector_ids );
			$can_edit = \in_array( \get_current_user_id(), $inspector_ids, true )
				|| \current_user_can( 'edit_any_inspection_finding' )
				|| \current_user_can( 'manage_options' );
			$recorded_by = $finding->inspector_names ? $finding->inspector_names : null;
			// Only needed to warn before an EDIT — skip the COUNT query for
			// read-only viewers (the hot path is opening plots you can't edit).
			$has_notice  = $can_edit
				&& \class_exists( '\AllotmentManager\Inspections\Inspection_Finding' )
				&& \AllotmentManager\Inspections\Inspection_Finding::finding_has_notice( (int) $finding->id );
			$edited = ! empty( $finding->updated_at ) && ! empty( $finding->created_at )
				&& \strtotime( (string) $finding->updated_at ) > \strtotime( (string) $finding->created_at ) + 2;
			$edited_at = $edited ? $finding->updated_at : null;
		}

		$first = trim( (string) ( $row->first_name ?? '' ) );
		$last  = trim( (string) ( $row->last_name ?? '' ) );
		$name  = trim( $first . ' ' . $last );

		$effective_member_id = ! empty( $row->effective_member_id ) ? (int) $row->effective_member_id : 0;
		$start_date          = ! empty( $row->assignment_start_date ) ? (string) $row->assignment_start_date : null;

		\wp_send_json_success(
			[
				'plot'    => [
					'id'                => (int) $row->id,
					'plotNumber'        => $row->plot_number,
					'section'           => $row->section,
					'memberId'          => $effective_member_id ?: null,
					'tenantName'        => '' !== $name ? $name : null,
					'membershipNumber'  => $row->membership_number,
					// Occupancy state — see format_plot_row. Vacant → not
					// inspectable client-side; new tenant → shown flagged, the
					// server auto-exempts so no notice is issued.
					'isVacant'          => 0 === $effective_member_id,
					// Own-plot guard — see holder_is_current_user(). The finding
					// editor blocks recording when true (mirrors the vacant block).
					'isOwnPlot'         => self::holder_is_current_user( $row ),
					'isNewTenant'       => self::is_new_tenant( $effective_member_id, $start_date ),
					'tenantStartDate'   => $start_date,
				],
				'finding' => $finding ? [
					'id'                 => (int) $finding->id,
					'complianceCategory' => $finding->compliance_category,
					'complianceStatus'   => $finding->compliance_status,
					'findingsSummary'    => $finding->findings_summary,
					// Committee-only note (manual exemption / internal review).
					// The PWA is a committee tool, so it's fine to return here;
					// the main plugin keeps it off the member portal.
					'committeeNotes'     => $finding->committee_notes,
					'requiresFollowup'   => (bool) $finding->requires_followup,
					// Issue-tickbox columns (DB 2.11.2). Null = inspector
					// didn't assess this aspect; 0/false = explicitly
					// recorded "no issue present"; 1/true = ticked.
					// Cast preserves the tri-state: null stays null, the
					// rest become bool — the finding-editor pre-populates
					// from `hasX !== undefined ? !!hasX : null`.
					'hasRubbish'              => null === $finding->has_rubbish              ? null : (bool) $finding->has_rubbish,
					'hasOvergrownWeeds'       => null === $finding->has_overgrown_weeds      ? null : (bool) $finding->has_overgrown_weeds,
					'hasUncultivatedAreas'    => null === $finding->has_uncultivated_areas   ? null : (bool) $finding->has_uncultivated_areas,
					'hasDerelictStructures'   => null === $finding->has_derelict_structures  ? null : (bool) $finding->has_derelict_structures,
					'hasTenancyBreach'        => null === $finding->has_tenancy_breach       ? null : (bool) $finding->has_tenancy_breach,
					'tenancyBreachDescription' => $finding->tenancy_breach_description,
					// Which visit this is. The app labels the screen with it and
					// decides from it whether it is correcting a result or
					// recording a new one.
					'visitSequence' => (int) $finding->visit_sequence,
						'canEdit'    => $can_edit,
						'recordedBy' => $recorded_by,
						'hasNotice'  => $has_notice,
						'edited'     => $edited,
						'editedAt'   => $edited_at,
				] : null,
				'photos'  => $photos,
				// On a follow-up, what the FIRST round found — the work the tenant
				// was asked to do, with its photographs. Null on a primary round.
				// The inspector cannot verify that required work was done without
				// it (#43).
				'previousFinding' => self::previous_finding( $round_id, $plot_id ),
				// Whether the app may offer to record the re-inspection.
				'followUpAvailable' => self::follow_up_available(
					$finding,
					$effective_member_id,
					self::holder_is_current_user( $row )
				),
			]
		);
	}
}
