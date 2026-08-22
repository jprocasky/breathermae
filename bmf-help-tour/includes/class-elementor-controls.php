<?php
/**
 * Elementor controls for BMF Help Tour.
 *
 * Adds a "Help Tour" section on Containers, Sections, Columns, and all widgets.
 * Values are written as bmf-help-* attributes on the element wrapper so the
 * existing JS engine picks them up unchanged.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BMF_Help_Tour_Elementor_Controls {

	/** @var BMF_Help_Tour_Elementor_Controls|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'elementor/init', array( $this, 'register_hooks' ) );
	}

	public function register_hooks() {
		// Layout elements.
		add_action(
			'elementor/element/container/section_layout/after_section_end',
			array( $this, 'register_help_tour_controls' ),
			10,
			2
		);
		add_action(
			'elementor/element/section/section_advanced/after_section_end',
			array( $this, 'register_help_tour_controls' ),
			10,
			2
		);
		add_action(
			'elementor/element/column/section_advanced/after_section_end',
			array( $this, 'register_help_tour_controls' ),
			10,
			2
		);

		// All widgets (Button, Heading, Image, Text Editor, Icon Box, etc.).
		// _section_style is the last core Advanced-adjacent section on common widgets.
		add_action(
			'elementor/element/common/_section_style/after_section_end',
			array( $this, 'register_help_tour_controls' ),
			10,
			2
		);

		// Single hook after Elementor has applied its own attributes (incl. Custom Attributes).
		// Avoids double-write / append that duplicated title + body text.
		add_action( 'elementor/element/after_add_attributes', array( $this, 'render_attributes' ) );
	}

	/**
	 * Add Help Tour controls section (shared by containers, sections, columns, widgets).
	 *
	 * @param \Elementor\Controls_Stack $element
	 * @param array                     $args
	 */
	public function register_help_tour_controls( $element, $args ) {
		// Avoid duplicate section if a hook fires twice for the same element type.
		if ( $element->get_controls( 'bmf_help_tour_section' ) ) {
			return;
		}

		$element->start_controls_section(
			'bmf_help_tour_section',
			array(
				'label' => __( 'Help Tour', 'bmf-help-tour' ),
				'tab'   => \Elementor\Controls_Manager::TAB_ADVANCED,
			)
		);

		// render_type => none: do not re-render the canvas on every keystroke.
		// These settings only become attributes on the frontend; live preview is unnecessary.

		$element->add_control(
			'bmf_help_enable',
			array(
				'label'        => __( 'Use as tour step', 'bmf-help-tour' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'bmf-help-tour' ),
				'label_off'    => __( 'No', 'bmf-help-tour' ),
				'return_value' => 'yes',
				'default'      => '',
				'render_type'  => 'none',
			)
		);

		$element->add_control(
			'bmf_help_step',
			array(
				'label'       => __( 'Step number', 'bmf-help-tour' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'min'         => 1,
				'step'        => 1,
				'default'     => 1,
				'render_type' => 'none',
				'condition'   => array(
					'bmf_help_enable' => 'yes',
				),
			)
		);

		$element->add_control(
			'bmf_help_title',
			array(
				'label'       => __( 'Title', 'bmf-help-tour' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => __( 'Start Here', 'bmf-help-tour' ),
				'label_block' => true,
				'render_type' => 'none',
				'condition'   => array(
					'bmf_help_enable' => 'yes',
				),
			)
		);

		$element->add_control(
			'bmf_help_text',
			array(
				'label'       => __( 'Body text', 'bmf-help-tour' ),
				'type'        => \Elementor\Controls_Manager::TEXTAREA,
				'default'     => '',
				'placeholder' => __( 'This is where you begin…', 'bmf-help-tour' ),
				'rows'        => 4,
				'render_type' => 'none',
				'condition'   => array(
					'bmf_help_enable' => 'yes',
				),
			)
		);

		$element->add_control(
			'bmf_help_icon',
			array(
				'label'       => __( 'Icon', 'bmf-help-tour' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => '',
				'options'     => array(
					''         => __( 'None', 'bmf-help-tour' ),
					'arrow'    => __( 'Arrow', 'bmf-help-tour' ),
					'info'     => __( 'Info', 'bmf-help-tour' ),
					'warning'  => __( 'Warning', 'bmf-help-tour' ),
					'question' => __( 'Question', 'bmf-help-tour' ),
				),
				'render_type' => 'none',
				'condition'   => array(
					'bmf_help_enable' => 'yes',
				),
			)
		);

		$element->add_control(
			'bmf_help_importance',
			array(
				'label'       => __( 'Importance', 'bmf-help-tour' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => 'info',
				'options'     => array(
					'info'     => __( 'Info', 'bmf-help-tour' ),
					'warning'  => __( 'Warning', 'bmf-help-tour' ),
					'question' => __( 'Question', 'bmf-help-tour' ),
				),
				'render_type' => 'none',
				'condition'   => array(
					'bmf_help_enable' => 'yes',
				),
			)
		);

		$element->add_control(
			'bmf_help_note',
			array(
				'type'            => \Elementor\Controls_Manager::RAW_HTML,
				'raw'             => __( 'Steps are ordered by step number. Hidden elements (responsive or WP Fusion) are skipped automatically.', 'bmf-help-tour' ),
				'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
				'condition'       => array(
					'bmf_help_enable' => 'yes',
				),
			)
		);

		$element->end_controls_section();
	}

	/**
	 * Write bmf-help-* attributes onto the element wrapper.
	 *
	 * Uses overwrite=true so multiple hooks (before_render + after_add_attributes)
	 * do not append and duplicate values (e.g. title shown twice).
	 *
	 * @param \Elementor\Element_Base $element
	 */
	public function render_attributes( $element ) {
		$settings = $element->get_settings_for_display();

		if ( empty( $settings['bmf_help_enable'] ) || 'yes' !== $settings['bmf_help_enable'] ) {
			return;
		}

		$step = isset( $settings['bmf_help_step'] ) ? absint( $settings['bmf_help_step'] ) : 0;
		if ( $step < 1 ) {
			return;
		}

		// 4th arg true = overwrite (do not append).
		$element->add_render_attribute( '_wrapper', 'bmf-help-step', (string) $step, true );

		$title = isset( $settings['bmf_help_title'] ) ? trim( (string) $settings['bmf_help_title'] ) : '';
		if ( '' !== $title ) {
			$element->add_render_attribute( '_wrapper', 'bmf-help-title', $title, true );
		}

		$text = isset( $settings['bmf_help_text'] ) ? trim( (string) $settings['bmf_help_text'] ) : '';
		if ( '' !== $text ) {
			$element->add_render_attribute( '_wrapper', 'bmf-help-text', $text, true );
		}

		$icon = isset( $settings['bmf_help_icon'] ) ? trim( (string) $settings['bmf_help_icon'] ) : '';
		if ( '' !== $icon ) {
			$element->add_render_attribute( '_wrapper', 'bmf-help-icon', $icon, true );
		}

		$importance = isset( $settings['bmf_help_importance'] ) ? trim( (string) $settings['bmf_help_importance'] ) : '';
		if ( '' !== $importance ) {
			$element->add_render_attribute( '_wrapper', 'bmf-help-importance', $importance, true );
		}
	}
}
