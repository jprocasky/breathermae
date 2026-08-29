<?php
/**
 * Shortcodes.
 *
 * @package BMF_Calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BMF_Calendar_Shortcodes {

	/** @var BMF_Calendar_Shortcodes|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'bmf_my_appointments', array( $this, 'my_appointments' ) );
		add_shortcode( 'bmf_member_appointments', array( $this, 'member_appointments' ) );
		add_shortcode( 'bmf_provider_calendar', array( $this, 'provider_calendar' ) );
		add_shortcode( 'bmf_request_appointment', array( $this, 'request_appointment' ) );
		add_shortcode( 'bmf_open_requests', array( $this, 'open_requests' ) );
		add_shortcode( 'bmf_provider_agenda', array( $this, 'provider_agenda' ) );
		add_shortcode( 'bmf_booked_calendar', array( $this, 'booked_calendar' ) );
		add_shortcode( 'bmf_coverage_calendar', array( $this, 'coverage_calendar' ) );
	}

	private function enqueue() {
		// Never load assets during AJAX or on auth screens.
		if ( wp_doing_ajax() || is_admin() ) {
			return;
		}
		wp_enqueue_style( 'bmf-calendar' );
		wp_enqueue_script( 'bmf-calendar' );
	}

	/**
	 * Options for 00 / 30 minute pickers.
	 */
	private static function half_hour_options( $selected = '09:00' ) {
		$selected = substr( (string) $selected, 0, 5 );
		$html     = '';
		for ( $h = 0; $h < 24; $h++ ) {
			foreach ( array( 0, 30 ) as $m ) {
				$val = sprintf( '%02d:%02d', $h, $m );
				$html .= '<option value="' . esc_attr( $val ) . '"' . selected( $selected, $val, false ) . '>' . esc_html( $val ) . '</option>';
			}
		}
		return $html;
	}

	/**
	 * Shared panel shell used by list-style shortcodes.
	 */
	private function render_list_panel( $args ) {
		$defaults = array(
			'mode'         => 'member',
			'user_id'      => 0,
			'email'        => '',
			'member_id'    => 0,
			'provider_id'  => 0,
			'can_edit'     => false,
			'title'        => '',
			'empty_msg'    => __( 'No appointments found.', 'bmf-calendar' ),
			'show_toolbar' => true,
		);
		$a = wp_parse_args( $args, $defaults );

		ob_start();
		?>
		<div class="bmf-cal-panel bmf-cal-list-panel"
			 data-mode="<?php echo esc_attr( $a['mode'] ); ?>"
			 data-user-id="<?php echo esc_attr( (int) $a['user_id'] ); ?>"
			 data-email="<?php echo esc_attr( $a['email'] ); ?>"
			 data-member-id="<?php echo esc_attr( (int) $a['member_id'] ); ?>"
			 data-provider-id="<?php echo esc_attr( (int) $a['provider_id'] ); ?>"
			 data-can-edit="<?php echo $a['can_edit'] ? '1' : '0'; ?>">

			<?php if ( $a['title'] ) : ?>
				<div class="bmf-cal-header">
					<h3 class="bmf-cal-title"><?php echo esc_html( $a['title'] ); ?></h3>
				</div>
			<?php endif; ?>

			<?php if ( $a['show_toolbar'] && $a['can_edit'] ) : ?>
				<div class="bmf-cal-toolbar">
					<button type="button" class="bmf-cal-btn bmf-cal-btn-primary bmf-cal-add">
						<?php esc_html_e( 'Add appointment', 'bmf-calendar' ); ?>
					</button>
				</div>
			<?php endif; ?>

			<div class="bmf-cal-list" data-empty="<?php echo esc_attr( $a['empty_msg'] ); ?>">
				<p class="bmf-cal-placeholder bmf-cal-loading"><?php esc_html_e( 'Loading…', 'bmf-calendar' ); ?></p>
			</div>

			<?php if ( $a['can_edit'] ) : ?>
				<div class="bmf-cal-form-wrap" hidden>
					<form class="bmf-cal-form">
						<input type="hidden" name="id" value="" />
						<input type="hidden" name="member_id" value="<?php echo esc_attr( (int) $a['member_id'] ); ?>" />
						<input type="hidden" name="email" value="<?php echo esc_attr( $a['email'] ); ?>" />

						<div class="bmf-cal-field">
							<label><?php esc_html_e( 'Subject', 'bmf-calendar' ); ?></label>
							<input type="text" name="subject" required maxlength="255" />
						</div>

						<div class="bmf-cal-field-row">
							<div class="bmf-cal-field">
								<label><?php esc_html_e( 'Start', 'bmf-calendar' ); ?></label>
								<input type="datetime-local" name="start" required step="1800" />
							</div>
							<div class="bmf-cal-field">
								<label><?php esc_html_e( 'End', 'bmf-calendar' ); ?></label>
								<input type="datetime-local" name="end" step="1800" />
							</div>
						</div>

						<div class="bmf-cal-field">
							<label><?php esc_html_e( 'Status', 'bmf-calendar' ); ?></label>
							<select name="status">
								<option value="confirmed"><?php esc_html_e( 'Confirmed', 'bmf-calendar' ); ?></option>
								<option value="requested"><?php esc_html_e( 'Requested', 'bmf-calendar' ); ?></option>
								<option value="completed"><?php esc_html_e( 'Completed', 'bmf-calendar' ); ?></option>
								<option value="cancelled"><?php esc_html_e( 'Cancelled', 'bmf-calendar' ); ?></option>
								<option value="no_show"><?php esc_html_e( 'No-show', 'bmf-calendar' ); ?></option>
							</select>
						</div>

						<div class="bmf-cal-field">
							<label><?php esc_html_e( 'Location', 'bmf-calendar' ); ?></label>
							<input type="text" name="location" maxlength="255" />
						</div>

						<div class="bmf-cal-field">
							<label><?php esc_html_e( 'Notes', 'bmf-calendar' ); ?></label>
							<textarea name="description" rows="3"></textarea>
						</div>

						<div class="bmf-cal-form-actions">
							<button type="submit" class="bmf-cal-btn bmf-cal-btn-primary"><?php esc_html_e( 'Save', 'bmf-calendar' ); ?></button>
							<button type="button" class="bmf-cal-btn bmf-cal-btn-ghost bmf-cal-form-cancel"><?php esc_html_e( 'Cancel', 'bmf-calendar' ); ?></button>
						</div>
					</form>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [bmf_my_appointments]
	 */
	public function my_appointments( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<p class="bmf-cal-msg">' . esc_html__( 'Please log in to view your appointments.', 'bmf-calendar' ) . '</p>';
		}

		$this->enqueue();
		$user = wp_get_current_user();

		return $this->render_list_panel(
			array(
				'mode'      => 'member',
				'user_id'   => $user->ID,
				'email'     => $user->user_email,
				'can_edit'  => false,
				'title'     => __( 'My appointments', 'bmf-calendar' ),
				'empty_msg' => __( 'You have no upcoming appointments.', 'bmf-calendar' ),
			)
		);
	}

	/**
	 * [bmf_member_appointments]
	 * Provider view for a specific member (or ULS selected member).
	 */
	public function member_appointments( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'member_id' => 0,
				'email'     => '',
			),
			$atts,
			'bmf_member_appointments'
		);

		if ( ! BMF_Calendar_Provider::is_provider() && ! current_user_can( 'manage_options' ) ) {
			return '<p class="bmf-cal-msg">' . esc_html__( 'Provider access required.', 'bmf-calendar' ) . '</p>';
		}

		$this->enqueue();

		return $this->render_list_panel(
			array(
				'mode'         => 'provider',
				'member_id'    => (int) $atts['member_id'],
				'email'        => $atts['email'],
				'provider_id'  => get_current_user_id(),
				'can_edit'     => true,
				'title'        => __( 'Member appointments', 'bmf-calendar' ),
				'empty_msg'    => __( 'No appointments for this member. Select a member or add one.', 'bmf-calendar' ),
				'show_toolbar' => true,
			)
		);
	}

	/**
	 * [bmf_provider_calendar] – availability manager for the current Provider.
	 */
	public function provider_calendar( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		if ( ! BMF_Calendar_Provider::is_provider() && ! current_user_can( 'manage_options' ) ) {
			return '<p class="bmf-cal-msg">' . esc_html__( 'Provider access required.', 'bmf-calendar' ) . '</p>';
		}

		$this->enqueue();
		$days = array(
			1 => __( 'Monday', 'bmf-calendar' ),
			2 => __( 'Tuesday', 'bmf-calendar' ),
			3 => __( 'Wednesday', 'bmf-calendar' ),
			4 => __( 'Thursday', 'bmf-calendar' ),
			5 => __( 'Friday', 'bmf-calendar' ),
			6 => __( 'Saturday', 'bmf-calendar' ),
			7 => __( 'Sunday', 'bmf-calendar' ),
		);

		ob_start();
		?>
		<div class="bmf-cal-panel bmf-cal-provider-calendar"
			 data-mode="provider-calendar"
			 data-provider-id="<?php echo esc_attr( get_current_user_id() ); ?>">

			<div class="bmf-cal-outlook-bar" data-outlook="1">
				<span class="bmf-cal-outlook-label"><?php esc_html_e( 'Outlook', 'bmf-calendar' ); ?></span>
				<span class="bmf-cal-outlook-state"><?php esc_html_e( 'Checking…', 'bmf-calendar' ); ?></span>
				<a class="bmf-cal-btn bmf-cal-btn-sm bmf-cal-outlook-connect" href="<?php echo esc_url( BMF_Calendar_Outlook::connect_url() ); ?>" hidden>
					<?php esc_html_e( 'Connect Outlook', 'bmf-calendar' ); ?>
				</a>
				<button type="button" class="bmf-cal-btn bmf-cal-btn-sm bmf-cal-outlook-disconnect" hidden>
					<?php esc_html_e( 'Disconnect', 'bmf-calendar' ); ?>
				</button>
			</div>

			<div class="bmf-cal-header">
				<h3 class="bmf-cal-title"><?php esc_html_e( 'My availability', 'bmf-calendar' ); ?></h3>
				<p class="bmf-cal-tz-note"><?php
					$tz_now = new DateTime( 'now', wp_timezone() );
					echo esc_html( sprintf(
						__( 'Enter weekly hours in your local time. They are stored in site time (%1$s, %2$s). Slots are shown in your local time.', 'bmf-calendar' ),
						wp_timezone()->getName(),
						$tz_now->format( 'T' )
					) );
				?></p>
			</div>

			<section class="bmf-cal-avail-section">
				<h4 class="bmf-cal-subtitle"><?php esc_html_e( 'Weekly hours', 'bmf-calendar' ); ?></h4>
				<ul class="bmf-cal-avail-list bmf-cal-avail-recurring"></ul>
				<form class="bmf-cal-avail-form bmf-cal-avail-form-recurring">
					<select name="day_of_week" required>
						<?php foreach ( $days as $n => $label ) : ?>
							<option value="<?php echo esc_attr( $n ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<select name="start_time" required>
						<?php echo self::half_hour_options( '09:00' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</select>
					<span class="bmf-cal-avail-to"><?php esc_html_e( 'to', 'bmf-calendar' ); ?></span>
					<select name="end_time" required>
						<?php echo self::half_hour_options( '17:00' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</select>
					<button type="submit" class="bmf-cal-btn bmf-cal-btn-primary"><?php esc_html_e( 'Add hours', 'bmf-calendar' ); ?></button>
				</form>
			</section>

			<section class="bmf-cal-avail-section">
				<h4 class="bmf-cal-subtitle"><?php esc_html_e( 'Blocked dates', 'bmf-calendar' ); ?></h4>
				<ul class="bmf-cal-avail-list bmf-cal-avail-exceptions"></ul>
				<form class="bmf-cal-avail-form bmf-cal-avail-form-exception">
					<input type="date" name="date_specific" required />
					<button type="submit" class="bmf-cal-btn"><?php esc_html_e( 'Block date', 'bmf-calendar' ); ?></button>
				</form>
			</section>

			<section class="bmf-cal-avail-section">
				<h4 class="bmf-cal-subtitle"><?php esc_html_e( 'Upcoming open slots', 'bmf-calendar' ); ?></h4>
				<div class="bmf-cal-slot-preview">
					<p class="bmf-cal-placeholder"><?php esc_html_e( 'Add weekly hours to see open slots.', 'bmf-calendar' ); ?></p>
				</div>
			</section>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [bmf_request_appointment]
	 * Member picks a provider (optional) and a slot.
	 */
	public function request_appointment( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<p class="bmf-cal-msg">' . esc_html__( 'Please log in to request an appointment.', 'bmf-calendar' ) . '</p>';
		}

		$atts = shortcode_atts(
			array(
				'provider_id' => 0,
				'exclude'     => '',
			),
			$atts,
			'bmf_request_appointment'
		);

		$this->enqueue();

		ob_start();
		?>
		<div class="bmf-cal-panel bmf-cal-request"
			 data-mode="request"
			 data-provider-id="<?php echo esc_attr( (int) $atts['provider_id'] ); ?>"
			 data-exclude="<?php echo esc_attr( $atts['exclude'] ); ?>"
			 data-member-id="<?php echo esc_attr( get_current_user_id() ); ?>">

			<div class="bmf-cal-header">
				<h3 class="bmf-cal-title"><?php esc_html_e( 'Request an appointment', 'bmf-calendar' ); ?></h3>
				<p class="bmf-cal-tz-note"><?php esc_html_e( 'Times are shown in your local timezone. If it differs from the site timezone, the site time is shown as a hint.', 'bmf-calendar' ); ?></p>
			</div>

			<div class="bmf-cal-field">
				<label><?php esc_html_e( 'Provider', 'bmf-calendar' ); ?></label>
				<select class="bmf-cal-provider-select">
					<option value="0"><?php esc_html_e( 'No specific provider', 'bmf-calendar' ); ?></option>
				</select>
			</div>

			<div class="bmf-cal-field">
				<label><?php esc_html_e( 'Subject', 'bmf-calendar' ); ?></label>
				<input type="text" class="bmf-cal-request-subject" maxlength="255" placeholder="<?php esc_attr_e( 'Appointment request', 'bmf-calendar' ); ?>" />
			</div>

			<div class="bmf-cal-slots-wrap">
				<p class="bmf-cal-placeholder bmf-cal-slots-hint"><?php esc_html_e( 'Choose a provider to see available times, or pick a date and time below.', 'bmf-calendar' ); ?></p>
				<div class="bmf-cal-slots"></div>
			</div>

			<div class="bmf-cal-general-time" hidden>
				<div class="bmf-cal-field">
					<label><?php esc_html_e( 'Preferred date & time', 'bmf-calendar' ); ?></label>
					<input type="datetime-local" class="bmf-cal-request-start" step="1800" />
				</div>
			</div>

			<div class="bmf-cal-field">
				<label><?php esc_html_e( 'Notes (optional)', 'bmf-calendar' ); ?></label>
				<textarea class="bmf-cal-request-notes" rows="2"></textarea>
			</div>

			<button type="button" class="bmf-cal-btn bmf-cal-btn-primary bmf-cal-request-submit" disabled>
				<?php esc_html_e( 'Request appointment', 'bmf-calendar' ); ?>
			</button>
			<p class="bmf-cal-request-status" hidden></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [bmf_open_requests]
	 * Provider inbox: requests assigned to them + unassigned requests.
	 */
	public function open_requests( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		if ( ! BMF_Calendar_Provider::is_provider() && ! current_user_can( 'manage_options' ) ) {
			return '<p class="bmf-cal-msg">' . esc_html__( 'Provider access required.', 'bmf-calendar' ) . '</p>';
		}

		$this->enqueue();

		ob_start();
		?>
		<div class="bmf-cal-panel bmf-cal-open-requests"
			 data-mode="open-requests"
			 data-provider-id="<?php echo esc_attr( get_current_user_id() ); ?>">
			<div class="bmf-cal-header">
				<h3 class="bmf-cal-title"><?php esc_html_e( 'Open requests', 'bmf-calendar' ); ?></h3>
			</div>
			<p class="bmf-cal-placeholder"><?php esc_html_e( 'Requests assigned to you, plus requests with no provider selected.', 'bmf-calendar' ); ?></p>
			<div class="bmf-cal-list">
				<p class="bmf-cal-placeholder bmf-cal-loading"><?php esc_html_e( 'Loading…', 'bmf-calendar' ); ?></p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [bmf_provider_agenda days="30"]
	 * Read-only confirmed appointments for the logged-in provider.
	 */
	public function provider_agenda( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		if ( ! BMF_Calendar_Provider::is_provider() && ! current_user_can( 'manage_options' ) ) {
			return '<p class="bmf-cal-msg">' . esc_html__( 'Provider access required.', 'bmf-calendar' ) . '</p>';
		}

		$atts = shortcode_atts(
			array(
				'days' => 30,
			),
			$atts,
			'bmf_provider_agenda'
		);

		$this->enqueue();

		ob_start();
		?>
		<div class="bmf-cal-panel bmf-cal-agenda"
			 data-mode="agenda"
			 data-days="<?php echo esc_attr( (int) $atts['days'] ); ?>"
			 data-provider-id="<?php echo esc_attr( get_current_user_id() ); ?>">
			<div class="bmf-cal-header">
				<h3 class="bmf-cal-title"><?php esc_html_e( 'My bookings', 'bmf-calendar' ); ?></h3>
				<p class="bmf-cal-tz-note"><?php esc_html_e( 'Your requested and confirmed appointments for the next 30 days.', 'bmf-calendar' ); ?></p>
			</div>
			<div class="bmf-cal-coverage-board">
				<p class="bmf-cal-placeholder bmf-cal-loading"><?php esc_html_e( 'Loading…', 'bmf-calendar' ); ?></p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [bmf_booked_calendar days="30" exclude="TEST"]
	 * 30-day grid of requested + confirmed appointments across providers.
	 */
	public function booked_calendar( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		if ( ! BMF_Calendar_Provider::is_provider() && ! current_user_can( 'manage_options' ) ) {
			return '<p class="bmf-cal-msg">' . esc_html__( 'Provider access required.', 'bmf-calendar' ) . '</p>';
		}
		$atts = shortcode_atts(
			array(
				'days'    => 30,
				'exclude' => '',
			),
			$atts,
			'bmf_booked_calendar'
		);
		$this->enqueue();
		ob_start();
		?>
		<div class="bmf-cal-panel bmf-cal-booked"
			 data-mode="booked"
			 data-days="<?php echo esc_attr( (int) $atts['days'] ); ?>"
			 data-exclude="<?php echo esc_attr( $atts['exclude'] ); ?>">
			<div class="bmf-cal-header">
				<h3 class="bmf-cal-title"><?php esc_html_e( 'Booked', 'bmf-calendar' ); ?></h3>
				<p class="bmf-cal-tz-note"><?php esc_html_e( 'Requested and confirmed appointments for the next 30 days.', 'bmf-calendar' ); ?></p>
			</div>
			<div class="bmf-cal-coverage-board">
				<p class="bmf-cal-placeholder bmf-cal-loading"><?php esc_html_e( 'Loading…', 'bmf-calendar' ); ?></p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [bmf_coverage_calendar exclude="TEST" days="30"]
	 * 30-day grid of published coverage windows only.
	 */
	public function coverage_calendar( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		if ( ! BMF_Calendar_Provider::is_provider() && ! current_user_can( 'manage_options' ) ) {
			return '<p class="bmf-cal-msg">' . esc_html__( 'Provider access required.', 'bmf-calendar' ) . '</p>';
		}
		$atts = shortcode_atts(
			array(
				'days'    => 30,
				'exclude' => '',
			),
			$atts,
			'bmf_coverage_calendar'
		);
		$this->enqueue();
		ob_start();
		?>
		<div class="bmf-cal-panel bmf-cal-coverage"
			 data-mode="coverage"
			 data-days="<?php echo esc_attr( (int) $atts['days'] ); ?>"
			 data-exclude="<?php echo esc_attr( $atts['exclude'] ); ?>">
			<div class="bmf-cal-header">
				<h3 class="bmf-cal-title"><?php esc_html_e( 'Coverage', 'bmf-calendar' ); ?></h3>
				<p class="bmf-cal-tz-note"><?php esc_html_e( 'Published coverage hours for the next 30 days. Empty days have no hours posted.', 'bmf-calendar' ); ?></p>
			</div>
			<div class="bmf-cal-coverage-board">
				<p class="bmf-cal-placeholder bmf-cal-loading"><?php esc_html_e( 'Loading…', 'bmf-calendar' ); ?></p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
