<?php

/**
 * WP CLI Queue runner.
 *
 * This class can only be called from within a WP CLI instance.
 */
class ActionScheduler_WPCLI_QueueRunner extends ActionScheduler_Abstract_QueueRunner {

	/** @var array */
	protected $actions;

	/** @var  ActionScheduler_ActionClaim */
	protected $claim;

	/** @var \cli\progress\Bar */
	protected $progress_bar;

	/**
	 * ActionScheduler_WPCLI_QueueRunner constructor.
	 *
	 * @param ActionScheduler_Store             $store
	 * @param ActionScheduler_FatalErrorMonitor $monitor
	 * @param ActionScheduler_QueueCleaner      $cleaner
	 *
	 * @throws Exception When this is not run within WP CLI
	 */
	public function __construct( ActionScheduler_Store $store, ActionScheduler_FatalErrorMonitor $monitor, ActionScheduler_QueueCleaner $cleaner ) {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			throw new Exception( __( 'The ' . __CLASS__ . ' class can only be run within WP CLI.', 'action-scheduler' ) );
		}

		parent::__construct( $store, $monitor, $cleaner );
	}

	/**
	 * Set up the Queue before processing.
	 *
	 * @author Jeremy Pry
	 *
	 * @param int  $batch_size The batch size to process.
	 * @param bool $force      Whether to force running even with too many concurrent processes.
	 *
	 * @return int The number of actions that will be run.
	 */
	public function setup( $batch_size, $force = false ) {
		$this->run_cleanup();
		$this->add_hooks();

		// Check to make sure there aren't too many concurrent processes running.
		$claim_count = $this->store->get_claim_count();
		$too_many    = $claim_count >= apply_filters( 'action_scheduler_queue_runner_concurrent_batches', 5 );
		if ( $too_many ) {
			if ( $force ) {
				WP_CLI::warning( __( 'There are too many concurrent batches, but the run is forced to continue.', 'action-scheduler' ) );
			} else {
				WP_CLI::error( __( 'There are too many concurrent batches.', 'action-scheduler' ) );
			}
		}

		// Stake a claim and store it.
		$this->claim = $this->store->stake_claim( $batch_size );
		$this->monitor->attach( $this->claim );
		$this->actions = $this->claim->get_actions();

		return count( $this->actions );
	}

	/**
	 * Run the queue cleaner.
	 *
	 * @author Jeremy Pry
	 */
	protected function run_cleanup() {
		$this->cleaner->clean();
	}

	/**
	 * Add our hooks to the appropriate actions.
	 *
	 * @author Jeremy Pry
	 */
	protected function add_hooks() {
		add_action( 'action_scheduler_before_execute', array( $this, 'before_execute' ) );
		add_action( 'action_scheduler_after_execute', array( $this, 'after_execute' ) );
		add_action( 'action_scheduler_failed_execution', array( $this, 'action_failed' ) );
	}

	/**
	 * Set up the WP CLI progress bar.
	 *
	 * @author Jeremy Pry
	 */
	protected function setup_progress_bar() {
		$count              = count( $this->actions );
		$this->progress_bar = \WP_CLI\Utils\make_progress_bar(
			sprintf( _n( 'Running %d task', 'Running %d tasks', $count, 'action-scheduler' ), number_format_i18n( $count ) ),
			$count
		);
	}

	/**
	 * Ensure the progress bar has finished properly.
	 *
	 * @author Jeremy Pry
	 */
	protected function finish_progress_bar() {
		$this->progress_bar->finish();
	}

	/**
	 * Process actions in the queue.
	 *
	 * @author Jeremy Pry
	 * @return int The number of actions processed.
	 */
	public function run() {
		$this->setup_progress_bar();
		foreach ( $this->actions as $action_id ) {
			// Error if we lost the claim.
			$all_actions = array_flip( $this->store->find_actions_by_claim_id( $this->claim->get_id() ) );
			if ( ! array_key_exists( $action_id, $all_actions ) ) {
				$this->finish_progress_bar();
				WP_CLI::error( __( 'The claim has been lost. Aborting.', 'action-scheduler' ) );
			}

			$this->process_action( $action_id );
			$this->progress_bar->tick();

			// Free up memory after every 50 items
			if ( 0 === $this->progress_bar->current() % 50 ) {
				$this->stop_the_insanity();
			}
		}

		$completed = $this->progress_bar->current();
		$this->finish_progress_bar();

		return $completed;
	}

	/**
	 * Handle WP CLI message when the action is starting.
	 *
	 * @author Jeremy Pry
	 *
	 * @param $action_id
	 */
	public function before_execute( $action_id ) {
		/* translators: %s refers to the action ID */
		WP_CLI::line( sprintf( __( 'Started processing action %s', 'action-scheduler' ), $action_id ) );
	}

	/**
	 * Handle WP CLI message when the action has completed.
	 *
	 * @author Jeremy Pry
	 *
	 * @param $action_id
	 */
	public function after_execute( $action_id ) {
		/* translators: %s refers to the action ID */
		WP_CLI::line( sprintf( __( 'Completed processing action %s', 'action-scheduler' ), $action_id ) );
	}

	/**
	 * Handle WP CLI message when the action has failed.
	 *
	 * @author Jeremy Pry
	 *
	 * @param int       $action_id
	 * @param Exception $exception
	 */
	public function action_failed( $action_id, $exception ) {
		WP_CLI::error(
			/* translators: %1$s refers to the action ID, %2$s refers to the Exception message */
			sprintf( __( 'Error processing action %1$s: %2$s', 'action-scheduler' ), $action_id, $exception->getMessage() ),
			false
		);
	}

	/**
	 * Sleep and help avoid hitting memory limit
	 *
	 * @param int $sleep_time Amount of seconds to sleep
	 */
	protected function stop_the_insanity( $sleep_time = 0 ) {
		if ( 0 < $sleep_time ) {
			WP_CLI::warning( sprintf( 'Stopped the insanity for %d %s', $sleep_time, _n( 'second', 'seconds', $sleep_time ) ) );
			sleep( $sleep_time );
		}

		WP_CLI::warning( __( 'Attempting to reduce used memory...', 'action-scheduler' ) );

		/**
		 * @var $wpdb            \wpdb
		 * @var $wp_object_cache \WP_Object_Cache
		 */
		global $wpdb, $wp_object_cache;

		$wpdb->queries = array();

		if ( ! is_object( $wp_object_cache ) ) {
			return;
		}

		$wp_object_cache->group_ops      = array();
		$wp_object_cache->stats          = array();
		$wp_object_cache->memcache_debug = array();
		$wp_object_cache->cache          = array();

		if ( is_callable( array( $wp_object_cache, '__remoteset' ) ) ) {
			call_user_func( array( $wp_object_cache, '__remoteset' ) ); // important
		}
	}
}
