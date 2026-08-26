<?php
/**
 * Recording the re-inspection in the field.
 *
 * A round is one per (year, site) and a re-inspection is visit 2 within it
 * (#883). The app could not record one: a plot that already had a finding
 * always opened for EDIT, so the only thing it could do to an inspected plot
 * was overwrite the primary result — the record the notice was served on —
 * while showing the inspector the work order and inviting them to record
 * against it. The server accepted the second visit all along; only the way in
 * was missing.
 *
 * Fixture tables are built by hand, for the reasons given at length in
 * test-inspect-followup-scope.php: the main plugin's migrations do not run in
 * this repo's test environment.
 *
 * @package AllotmentManagerInspections
 */

use AllotmentManagerInspections\Inspect_Ajax;

class Test_Inspect_Followup_Capture extends WP_UnitTestCase {

	private static string $findings = '';

	/** Only what THIS class created, so teardown never drops a real table. */
	private static array $created = array();

	/** Set when the environment's schema cannot hold a second visit. */
	private static bool $blocked = false;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		global $wpdb;

		self::$findings = $wpdb->prefix . 'am_inspection_findings';

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::$findings ) );
		if ( ! $found ) {
			// Only the columns latest_finding() selects. A column missing here
			// is a query error in CI only — CI builds these against a bare
			// WordPress with no main-plugin schema, while a developer pointing
			// WP_TESTS_DIR at the monorepo database finds the real table.
			$wpdb->query(
				'CREATE TABLE ' . self::$findings . " (
					id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
					round_id bigint(20) UNSIGNED NOT NULL,
					plot_id bigint(20) UNSIGNED NOT NULL,
					subdivision_identifier varchar(10) NOT NULL DEFAULT '',
					visit_sequence tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
					compliance_status varchar(30) NOT NULL DEFAULT 'compliant',
					compliance_category varchar(30) DEFAULT NULL,
					findings_summary text,
					committee_notes text,
					inspector_user_ids text,
					inspector_names varchar(255) DEFAULT NULL,
					inspection_date date DEFAULT NULL,
					has_rubbish tinyint(1) DEFAULT NULL,
					has_overgrown_weeds tinyint(1) DEFAULT NULL,
					has_uncultivated_areas tinyint(1) DEFAULT NULL,
					has_derelict_structures tinyint(1) DEFAULT NULL,
					has_tenancy_breach tinyint(1) DEFAULT NULL,
					tenancy_breach_description text,
					requires_followup tinyint(1) NOT NULL DEFAULT 0,
					voided_at datetime DEFAULT NULL,
					created_at datetime DEFAULT NULL,
					updated_at datetime DEFAULT NULL,
					PRIMARY KEY (id)
				)"
			);
			self::$created[] = self::$findings;
			return;
		}

		// The real table was found, and it may be an OLD one.
		//
		// A test database created before ams#881 carries the superseded
		// `round_plot` unique key (round, plot, subdivision) as well as the
		// `round_plot_visit` that replaced it — migrations are skipped in the
		// test environment, and Schema_Creator's CREATE TABLE IF NOT EXISTS
		// no-ops over an existing table, so nothing ever removes it. That index
		// refuses the SECOND VISIT outright, which is the whole subject of this
		// suite: every test here would fail with a duplicate-key error that
		// looks like a bug in the code under test.
		//
		// Both live hosts carry `round_plot_visit` alone (checked against
		// pallas and oncilla, 2026-08-26), so a database holding both is stale
		// rather than authoritative. Drop the stale one — this is a test
		// database, and this brings it into line with the declared schema.
		// If the drop is refused (an FK leaning on the index, ams#883's errno
		// 1553 trap), skip rather than fail: the failure would otherwise read
		// as a defect in latest_finding().
		$indexes = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME LIKE %s',
				$wpdb->dbname,
				self::$findings,
				'round_plot%'
			)
		);

		if ( in_array( 'round_plot', $indexes, true ) && in_array( 'round_plot_visit', $indexes, true ) ) {
			$wpdb->query( 'ALTER TABLE ' . self::$findings . ' DROP INDEX round_plot' );
		}

		$stale = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s',
				$wpdb->dbname,
				self::$findings,
				'round_plot'
			)
		);
		self::$blocked = (bool) (int) $stale;
	}

	/**
	 * Whether this environment can hold a second visit at all.
	 *
	 * @return void
	 */
	private function require_two_visits(): void {
		if ( self::$blocked ) {
			$this->markTestSkipped(
				'This test database still carries the pre-ams#881 `round_plot` unique key, which refuses a second visit. '
				. 'Recreate it, or drop that index by hand.'
			);
		}
	}

	public static function tearDownAfterClass(): void {
		global $wpdb;
		foreach ( self::$created as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
		self::$created = array();
		parent::tearDownAfterClass();
	}

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . self::$findings );
	}

	/**
	 * Record one visit.
	 *
	 * @param int         $visit     Visit sequence.
	 * @param string      $summary   Something to tell the rows apart by.
	 * @param string|null $voided_at Void timestamp, or null.
	 * @return int Finding ID.
	 */
	private function record_visit( int $visit, string $summary, ?string $voided_at = null ): int {
		global $wpdb;
		$wpdb->insert(
			self::$findings,
			array(
				'round_id'           => 100,
				'plot_id'            => 200,
				'visit_sequence'     => $visit,
				'compliance_status'  => 'non_compliant',
				'findings_summary'   => $summary,
				'inspector_user_ids' => wp_json_encode( array( 1 ) ),
				'inspection_date'    => '2026-06-22',
				'voided_at'          => $voided_at,
				'created_at'         => '2026-06-22 09:00:00',
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * The editor must open the visit that stands, not whichever row comes back.
	 *
	 * Inserted in visit order, so a query with no ORDER BY passes by luck. The
	 * second test inserts them the other way round, which is what catches it.
	 *
	 * @since #50
	 */
	public function test_the_latest_visit_is_the_one_returned(): void {
		$this->require_two_visits();
		$this->record_visit( 1, 'primary' );
		$this->record_visit( 2, 'reinspection' );

		$finding = Inspect_Ajax::latest_finding( 100, 200 );

		$this->assertNotNull( $finding );
		$this->assertSame( 2, (int) $finding->visit_sequence );
		$this->assertSame( 'reinspection', $finding->findings_summary );
	}

	/**
	 * Insertion order must not decide it.
	 *
	 * @since #50
	 */
	public function test_the_latest_visit_wins_over_insertion_order(): void {
		$this->require_two_visits();
		$this->record_visit( 2, 'reinspection' );
		$this->record_visit( 1, 'primary' );

		$finding = Inspect_Ajax::latest_finding( 100, 200 );

		$this->assertSame( 2, (int) $finding->visit_sequence );
		$this->assertSame(
			'reinspection',
			$finding->findings_summary,
			'correcting the re-inspection must not edit the primary result the notice was served on'
		);
	}

	/**
	 * An uninspected plot has no finding.
	 *
	 * @since #50
	 */
	public function test_an_uninspected_plot_returns_null(): void {
		$this->assertNull( Inspect_Ajax::latest_finding( 100, 200 ) );
	}

	/**
	 * Another round's visits are not this round's.
	 *
	 * @since #50
	 */
	public function test_visits_are_scoped_to_the_round(): void {
		$this->record_visit( 1, 'primary' );

		$this->assertNull( Inspect_Ajax::latest_finding( 999, 200 ) );
		$this->assertNull( Inspect_Ajax::latest_finding( 100, 999 ) );
	}

	/**
	 * A re-inspection is offered exactly once: after the first visit.
	 *
	 * @since #50
	 */
	public function test_a_follow_up_is_offered_after_the_first_visit(): void {
		$this->record_visit( 1, 'primary' );
		$finding = Inspect_Ajax::latest_finding( 100, 200 );

		$this->assertTrue( Inspect_Ajax::follow_up_available( $finding, 7, false ) );
	}

	/**
	 * Not before the plot has been inspected at all — that is the first visit,
	 * which the editor already offers.
	 *
	 * @since #50
	 */
	public function test_no_follow_up_before_the_first_visit(): void {
		$this->assertFalse( Inspect_Ajax::follow_up_available( null, 7, false ) );
	}

	/**
	 * And not a third. create_finding() caps its inference at 2, so a third
	 * comes back as duplicate_finding — a button that can only fail.
	 *
	 * @since #50
	 */
	public function test_no_third_visit_is_offered(): void {
		$this->require_two_visits();
		$this->record_visit( 1, 'primary' );
		$this->record_visit( 2, 'reinspection' );
		$finding = Inspect_Ajax::latest_finding( 100, 200 );

		$this->assertFalse( Inspect_Ajax::follow_up_available( $finding, 7, false ) );
	}

	/**
	 * A voided visit means the tenancy ended mid-round: nothing to re-inspect.
	 *
	 * @since #50
	 */
	public function test_no_follow_up_on_a_voided_visit(): void {
		$this->record_visit( 1, 'primary', '2026-07-01 10:00:00' );
		$finding = Inspect_Ajax::latest_finding( 100, 200 );

		$this->assertFalse( Inspect_Ajax::follow_up_available( $finding, 7, false ) );
	}

	/**
	 * The bars on a first finding bind a follow-up too — it IS a new finding.
	 *
	 * A vacant plot has no member to record against, and the server rejects an
	 * inspector's own plot. Offering either would only queue a failure.
	 *
	 * @since #50
	 */
	public function test_the_vacant_and_own_plot_bars_apply(): void {
		$this->record_visit( 1, 'primary' );
		$finding = Inspect_Ajax::latest_finding( 100, 200 );

		$this->assertFalse( Inspect_Ajax::follow_up_available( $finding, 0, false ), 'vacant' );
		$this->assertFalse( Inspect_Ajax::follow_up_available( $finding, 7, true ), 'own plot' );
	}
}
