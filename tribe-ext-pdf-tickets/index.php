<?php
/**
 * Plugin Name:     Event Tickets Extension: PDF Tickets
 * Description:     When this extension plugin is active, Event Tickets (RSVP) and Event Tickets Plus (WooCommerce and Easy Digital Downloads) tickets will be turned into PDFs, saved to your Uploads directory (but not added to your Media Library), and attached to the outgoing ticket emails. Each attendee's ticket will be a separate PDF attachment to the single order email. The wp-admin Attendees Report Table will have a link to each PDF Ticket, allowing you to view any ticket in PDF format. The same will appear in an event's Community Events Tickets Attendees Report Table if you have that add-on activated as well. Attendees will be able to self-service and obtain a PDF copy of their ticket by viewing each event's "View your Tickets" link. This extension has no wp-admin settings to configure; deactivate this plugin if you want PDF Tickets functionality disabled.
 * Version:         1.0.0
 * Extension Class: Tribe__Extension__PDF_Tickets
 * Author:          Modern Tribe, Inc.
 * Author URI:      http://m.tri.be/1971
 * License:         GPLv2 or later
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 */

// Do not load unless Tribe Common is fully loaded.
if ( ! class_exists( 'Tribe__Extension' ) ) {
	return;
}

/**
 * Extension main class, class begins loading on init() function.
 */
class Tribe__Extension__PDF_Tickets extends Tribe__Extension {

	// TODO: check public, private, protected
	// TODO: docblocks
	// TODO: sanity to the order of methods
	// TODO: add action hook after email gets sent or PDF gets generated on the fly to allow for deleting PDFs
	// TODO: test with month upload folders enabled
	/**
	 * An array of the absolute file paths of the PDF(s) to be attached
	 * to the ticket email.
	 *
	 * One PDF attachment per attendee, even in a single order.
	 *
	 * @var array
	 */
	protected $attachments_array = array();

	/**
	 * Setup the Extension's properties.
	 *
	 * This always executes even if the required plugins are not present.
	 */
	public function construct() {
		// Always require ET 4.5.2+
		$this->add_required_plugin( 'Tribe__Tickets__Main', '4.5.2' );

		// if Event Tickets Plus, require 4.5.0.1+
		if ( class_exists( 'Tribe__Tickets_Plus__Main' ) ) {
			$this->add_required_plugin( 'Tribe__Tickets_Plus__Main', '4.5.0.1' );

			// if Community Events Tickets (only possible with Event Tickets Plus), require 4.4.3+
			if ( class_exists( 'Tribe__Events__Community__Tickets__Main' ) ) {
				$this->add_required_plugin( 'Tribe__Events__Community__Tickets__Main', '4.4.3' );
			}

		}

		$this->set_url( 'https://theeventscalendar.com/extensions/pdf-tickets/' ); // TODO: Write article
	}

	/**
	 * Extension initialization and hooks.
	 *
	 * PHP 5.3.7+ is required to avoid blank page content via mPDF.
	 *
	 * @link https://mpdf.github.io/troubleshooting/known-issues.html
	 */
	public function init() {
		if ( version_compare( PHP_VERSION, '5.3.7', '<' ) ) {
			_doing_it_wrong(
				$this->get_name(),
				'This Extension requires PHP 5.3.7 or newer to work. Please contact your website host and inquire about updating PHP.',
				'1.0.0'
			);

			return;
		}

		// Event Tickets
		add_action( 'event_tickets_attendees_table_row_actions', array(
			$this,
			'pdf_attendee_table_row_actions'
		), 0, 2 );

		add_action( 'event_tickets_orders_attendee_contents', array(
			$this,
			'pdf_attendee_table_row_action_contents'
		), 10, 2 );

		add_action( 'event_tickets_rsvp_attendee_created', array(
			$this,
			'do_upload_pdf'
		), 50, 1 );

		// Event Tickets Plus: WooCommerce
		add_action( 'event_ticket_woo_attendee_created', array(
			$this,
			'do_upload_pdf'
		), 50, 1 );

		// Event Tickets Plus: Easy Digital Downloads
		add_action( 'event_ticket_edd_attendee_created', array(
			$this,
			'do_upload_pdf'
		), 50, 1 );

		// For generating a PDF on the fly
		add_action( 'template_redirect', array(
			$this,
			'if_pdf_url_404_upload_pdf_then_reload'
		) );
	}

	/**
	 * The absolute path to the mPDF library directory.
	 *
	 * @return string
	 */
	private function mpdf_lib_dir() {
		$path = __DIR__ . '/vendor/mpdf';

		return trailingslashit( $path );
	}

	/**
	 * Get the absolute path to the WordPress uploads directory,
	 * with a trailing slash.
	 *
	 * It will return a path to where the WordPress /uploads/ directory is,
	 * whether it is in the default location or whether a constant has been
	 * defined or a filter used to specify an alternate location. The path
	 * it returns will look something like
	 * /path/to/wordpress/wp-content/uploads/
	 * regardless of the "Organize my uploads into month- and year-based
	 * folders" option in wp-admin > Settings > Media.
	 *
	 * @return string The uploads directory path.
	 */
	protected function uploads_directory_path() {
		$upload_dir = wp_upload_dir();

		return trailingslashit( $upload_dir['basedir'] );
	}

	/**
	 * Get the URL to the WordPress uploads directory,
	 * with a trailing slash.
	 *
	 * It will return a URL to where the WordPress /uploads/ directory is,
	 * whether it is in the default location or whether a constant has been
	 * defined or a filter used to specify an alternate location. The URL
	 * it returns will look something like
	 * http://example.com/wp-content/uploads/ regardless of the current
	 * month we are in.
	 *
	 * @return string The uploads directory URL.
	 */
	protected function uploads_directory_url() {
		$upload_dir = wp_upload_dir();

		return trailingslashit( $upload_dir['baseurl'] );
	}

	/**
	 * Check if one string (haystack) starts with another string (needle).
	 *
	 * @param $full  The haystack string.
	 * @param $start The needle string.
	 *
	 * @return bool
	 */
	protected function string_starts_with( $full, $start ) {
		$string_position = strpos( $full, $start );
		if ( 0 === $string_position ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Check if one string (haystack) ends with another string (needle).
	 *
	 * @param $full The haystack string.
	 * @param $end  The needle string.
	 *
	 * @return bool
	 */
	protected function string_ends_with( $full, $end ) {
		$comparison = substr_compare( $full, $end, strlen( $full ) - strlen( $end ), strlen( $end ) );
		if ( 0 === $comparison ) {
			return true;
		} else {
			return false;
		}
	}

	// TODO: runs 4 times per attendee

	/**
	 * Determine the ticket type from the Attendee ID's custom field keys.
	 *
	 * This is accomplished by getting all of an Attendee record's custom
	 * fields (keys and values) and traversing through the keys (custom field
	 * names) until we find a key that matches the naming convention (starts
	 * with "_tribe" and ends with "_event") and returning this find (the
	 * full custom field key).
	 *
	 * @param $attendee_id
	 *
	 * @return bool|int|string If string, should be one of these 3:
	 *                         _tribe_rsvp_event
	 *                         _tribe_wooticket_event
	 *                         _tribe_eddticket_event
	 */
	protected function get_event_meta_key_from_attendee_id( $attendee_id ) {
		$attendee_id = absint( $attendee_id );

		if ( 0 >= $attendee_id ) {
			return false;
		}

		// Get all the custom fields for the Attendee Post ID
		$attendee_custom_fields = get_post_custom( $attendee_id );

		/**
		 * Find the first custom field key that ends in "_event", although
		 * there should not ever be more than one.
		 *
		 * The key is named this way even if a ticket is assigned to a post
		 * type other than tribe_events (e.g. post, page).
		 *
		 * Chose not to use get_post_meta() here because we do not know the
		 * ticket type; therefore, we do not know the exact meta_key to search
		 * for, and we want to avoid multiple database calls.
		 */
		foreach ( $attendee_custom_fields as $key => $value ) {
			/*
			* Could add a filter to fully support customizing
			* ATTENDEE_EVENT_KEY, but that would be edge case.
			*/
			$ends_in_event = $this->string_ends_with( $key, '_event' );

			if ( true === $ends_in_event ) {
				$starts_with_tribe = $this->string_starts_with( $key, '_tribe_' );
				if ( true === $starts_with_tribe ) {
					$event_key = $key;
					break;
				}
			}
		}

		if ( empty( $event_key ) ) {
			return false;
		}

		return $event_key;
	}

	/**
	 * Get the event/post ID from the Attendee ID.
	 *
	 * @see Tribe__Extension__PDF_Tickets::get_event_meta_key_from_attendee_id()
	 *
	 * @param $attendee_id
	 *
	 * @return bool|int
	 */
	protected function get_event_id_from_attendee_id( $attendee_id ) {
		$event_key = $this->get_event_meta_key_from_attendee_id( $attendee_id );

		$event_id = absint( get_post_meta( $attendee_id, $event_key, true ) );

		if ( empty( $event_id ) ) {
			return false;
		}

		return $event_id;

	}

	/**
	 * Get the ticket provider slug from an Attendee ID.
	 *
	 * @see Tribe__Extension__PDF_Tickets::get_event_meta_key_from_attendee_id()
	 *
	 * @param $attendee_id
	 *
	 * @return string
	 */
	protected function get_ticket_provider_slug_from_attendee_id( $attendee_id ) {
		$event_key = $this->get_event_meta_key_from_attendee_id( $attendee_id );
		/**
		 * Determine the ticket type from the event key
		 *
		 * These slugs are not just made up. They are the same as the
		 * 'provider_slug' key that comes from get_order_data() in each
		 * ticket provider's main classes. However, we are simply using
		 * them for file naming purposes.
		 */
		if ( false !== strpos( $event_key, 'rsvp' ) ) {
			$ticket_provider_slug = 'rsvp';
		} elseif ( false !== strpos( $event_key, 'wooticket' ) ) {
			$ticket_provider_slug = 'woo';
		} elseif ( false !== strpos( $event_key, 'eddticket' ) ) {
			$ticket_provider_slug = 'edd';
		} else {
			$ticket_provider_slug = ''; // unknown ticket type
		}

		return $ticket_provider_slug;
	}

	/**
	 * Get the ticket class' instance given a valid ticket provider slug.
	 *
	 * @param $ticket_provider_slug
	 *
	 * @return bool|Tribe__Tickets__RSVP|Tribe__Tickets_Plus__Commerce__EDD__Main|Tribe__Tickets_Plus__Commerce__WooCommerce__Main
	 */
	protected function get_ticket_provider_main_class_instance( $ticket_provider_slug ) {
		if ( 'rsvp' === $ticket_provider_slug ) {
			if ( ! class_exists( 'Tribe__Tickets__RSVP' ) ) {
				return false;
			} else {
				$instance = Tribe__Tickets__RSVP::get_instance();
			}
		} elseif ( 'woo' === $ticket_provider_slug ) {
			if ( ! class_exists( 'Tribe__Tickets_Plus__Commerce__WooCommerce__Main' ) ) {
				return false;
			} else {
				$instance = Tribe__Tickets_Plus__Commerce__WooCommerce__Main::get_instance();
			}
		} elseif ( 'edd' === $ticket_provider_slug ) {
			if ( ! class_exists( 'Tribe__Tickets_Plus__Commerce__EDD__Main' ) ) {
				return false;
			} else {
				$instance = Tribe__Tickets_Plus__Commerce__EDD__Main::get_instance();
			}
		} else {
			return false; // unknown ticket provider
		}

		return $instance;
	}

	/**
	 * PDF file name without leading server path or URL, created solely from
	 * the Attendee ID.
	 *
	 * Naming convention for this extension's PDFs.
	 *
	 * @param $attendee_id Ticket Attendee ID
	 *
	 * @return bool|string
	 */
	protected function get_pdf_name( $attendee_id = 0 ) {
		$event_id = $this->get_event_id_from_attendee_id( $attendee_id );

		$ticket_provider_slug = $this->get_ticket_provider_slug_from_attendee_id( $attendee_id );

		// Make sure Attendee ID is wrapped in double-underscores so get_attendee_id_from_attempted_url() works
		$file_name = sprintf( 'et_%d_%s__%d__',
			$event_id,
			$ticket_provider_slug,
			$attendee_id
		);

		$file_name .= md5( $file_name );

		$file_name = substr( $file_name, 0, 50 );

		// This filter is available if you decide you must use it, but note that some functionality may be lost if you utilize it.
		/**
		 * Filter to customize the file name of the generated PDF.
		 *
		 * Note that it also affects the lookup of existing PDF files (and
		 * guessing an Attendee's ID by assuming it is wrapped in double-
		 * underscores) so use at your own risk knowing that it is
		 * recommended to not use this filter if this is at all possible.
		 *
		 * @var $file_name
		 * @var $event_id
		 * @var $ticket_provider_slug
		 * @var $attendee_id
		 */
		$file_name = apply_filters( 'tribe_ext_pdf_tickets_get_pdf_name', $file_name, $event_id, $ticket_provider_slug, $attendee_id );

		$file_name .= '.pdf';

		// remove all whitespace from file name, just as a sanity check
		$file_name = preg_replace( '/\s+/', '', $file_name );

		return $file_name;
	}

	/**
	 * Get absolute path to the PDF file, inclusive of .pdf at the end.
	 *
	 * @see Tribe__Extension__PDF_Tickets::get_pdf_name()
	 *
	 * @param $attendee_id
	 *
	 * @return string
	 */
	public function get_pdf_path( $attendee_id ) {
		return $this->uploads_directory_path() . $this->get_pdf_name( $attendee_id );
	}

	/**
	 * Get the full URL to the PDF file, inclusive of .pdf at the end.
	 *
	 * @see Tribe__Extension__PDF_Tickets::get_pdf_name()
	 *
	 * @param $attendee_id
	 *
	 * @return string
	 */
	public function get_pdf_url( $attendee_id ) {
		return $this->uploads_directory_url() . $this->get_pdf_name( $attendee_id );
	}

	/**
	 * Create PDF, save to server, and add to email queue.
	 *
	 * @see Tribe__Extension__PDF_Tickets::get_ticket_provider_slug_from_attendee_id()
	 * @see Tribe__Extension__PDF_Tickets::get_ticket_provider_main_class_instance()
	 * @see Tribe__Tickets__RSVP::generate_tickets_email_content()
	 * @see Tribe__Tickets_Plus__Commerce__WooCommerce__Main::generate_tickets_email_content()
	 * @see Tribe__Tickets_Plus__Commerce__EDD__Main::generate_tickets_email_content()
	 * @see Tribe__Extension__PDF_Tickets::get_event_id_from_attendee_id()
	 * @see Tribe__Tickets__RSVP::get_attendees_array()
	 * @see Tribe__Tickets_Plus__Commerce__WooCommerce__Main::get_attendees_array()
	 * @see Tribe__Tickets_Plus__Commerce__EDD__Main::get_attendees_array()
	 * @see Tribe__Extension__PDF_Tickets::get_pdf_path()
	 * @see Tribe__Extension__PDF_Tickets::output_pdf()
	 *
	 * @param      $attendee_id ID of attendee ticket
	 * @param bool $email       Add PDF to email attachments array
	 *
	 * @return bool
	 */
	public function do_upload_pdf( $attendee_id, $email = true ) {
		$successful = false;

		$ticket_provider_slug = $this->get_ticket_provider_slug_from_attendee_id( $attendee_id );

		$ticket_instance = $this->get_ticket_provider_main_class_instance( $ticket_provider_slug );

		if ( empty( $ticket_instance ) ) {
			return $successful;
		}

		$event_id = $this->get_event_id_from_attendee_id( $attendee_id );

		$attendees = $ticket_instance->get_attendees_array( $event_id );

		if (
			empty( $attendees )
			|| ! is_array( $attendees )
		) {
			return $successful;
		}

		$attendees_array = array();

		foreach ( $attendees as $attendee ) {
			if ( $attendee['attendee_id'] == $attendee_id ) {
				$attendees_array[] = $attendee;
			}
		}

		if ( empty( $attendees_array ) ) {
			return $successful;
		}

		/**
		 * Because $html is the full HTML DOM sent to the PDF generator, adding
		 * anything to the beginning or the end would likely cause problems.
		 *
		 * If you want to alter what gets sent to the PDF generator, follow the
		 * Themer's Guide for tickets/email.php or use that template file's
		 * existing hooks.
		 *
		 * @link https://theeventscalendar.com/knowledgebase/themers-guide/#tickets
		 */
		$html = $ticket_instance->generate_tickets_email_content( $attendees_array );

		if ( empty( $html ) ) {
			return $successful;
		}

		$file_name = $this->get_pdf_path( $attendee_id );

		if ( empty( $file_name ) ) {
			return $successful;
		}

		if ( file_exists( $file_name ) ) {
			$successful = true;
		} else {
			$this->output_pdf( $html, $file_name );

			$successful = true;
		}

		/**
		 * Action hook after the PDF ticket gets created.
		 *
		 * Might be useful if you want the PDF file added to the Media Library via wp_insert_attachment(), for example.
		 *
		 * @var $event_id
		 * @var $attendee_id
		 * @var $ticket_provider_slug
		 * @var $file_name
		 */
		do_action( 'tribe_ext_pdf_tickets_uploaded_pdf', $event_id, $attendee_id, $ticket_provider_slug, $file_name );

		/**
		 * Filter to disable PDF email attachments, either entirely (just pass false)
		 * or per event, attendee, ticket type, or some other logic.
		 *
		 * @var $email
		 * @var $event_id
		 * @var $attendee_id
		 * @var $ticket_provider_slug
		 * @var $file_name
		 */
		$email = (bool) apply_filters( 'tribe_ext_pdf_tickets_attach_to_email', $email, $event_id, $attendee_id, $ticket_provider_slug, $file_name );

		if (
			true === $successful
			&& true === $email
		) {
			$this->attachments_array[] = $file_name;

			if ( 'rsvp' === $ticket_provider_slug ) {
				add_filter( 'tribe_rsvp_email_attachments', array(
					$this,
					'email_attach_pdf'
				) );
			} elseif ( 'woo' === $ticket_provider_slug ) {
				// TODO: ticket attachments are getting added to Your Tickets, Order Receipt, and Admin Notification emails -- likely only want it attached to the Your Tickets email -- didn't fully test the other two.
				add_filter( 'woocommerce_email_attachments', array(
					$this,
					'email_attach_pdf'
				) );
			} elseif ( 'edd' === $ticket_provider_slug ) {
				add_filter( 'edd_ticket_receipt_attachments', array(
					$this,
					'email_attach_pdf'
				) );
			} else {
				// unknown ticket type so no emailing to do
			}
		}

		return $successful;
	}

	/**
	 * Attach the queued PDF(s) to the ticket email.
	 *
	 * RSVP, Woo, and EDD filters all just pass an attachments array so we can
	 * get away with a single, simple function here.
	 *
	 * @param $attachments
	 *
	 * @return array
	 */
	public function email_attach_pdf( $attachments ) {
		$attachments = array_merge( $attachments, $this->attachments_array );

		// just a sanity check
		$attachments = array_unique( $attachments );

		return $attachments;
	}

	/**
	 * Create the HTML link markup (a href) for a PDF Ticket file.
	 *
	 * @see Tribe__Extension__PDF_Tickets::get_pdf_url()
	 *
	 * @param $attendee_id
	 *
	 * @return string
	 */
	public function ticket_link( $attendee_id ) {
		$text = __( 'PDF Ticket', 'tribe-extension' );

		$target = apply_filters( 'tribe_ext_pdf_tickets_link_target', '_blank' );

		$output = sprintf(
			'<a href="%s"',
			esc_attr( $this->get_pdf_url( $attendee_id ) )
		);

		if ( ! empty( $target ) ) {
			$output .= sprintf( ' target="%s"',
				esc_attr( $target )
			);
		}

		$output .= sprintf(
			' class="tribe-ext-pdf-ticket-link">%s</a>',
			esc_html( $text )
		);

		return $output;
	}

	/**
	 * Add link to the PDF ticket to the front-end "View your Tickets" page.
	 *
	 * @see Tribe__Extension__PDF_Tickets::ticket_link()
	 */
	public function pdf_attendee_table_row_action_contents( $attendee ) {
		echo $this->ticket_link( $attendee['attendee_id'] );
	}


	/**
	 * Add a link to each ticket's PDF ticket on the wp-admin Attendee List.
	 *
	 * Community Events Tickets' Attendee List/Table comes from the same
	 * source as the wp-admin one so no extra work to get it working there.
	 *
	 * @see Tribe__Extension__PDF_Tickets::ticket_link()
	 */
	public function pdf_attendee_table_row_actions( $actions, $item ) {
		$actions[] = $this->ticket_link( $item['attendee_id'] );

		return $actions;
	}

	/**
	 * Outputs PDF
	 *
	 * @see  Tribe__Extension__PDF_Tickets::get_mpdf()
	 * @see  mPDF::Output()
	 *
	 * @param string $html HTML content to be turned into PDF.
	 * @param string $file_name Full file name, including path on server.
	 *                          The name of the file. If not specified, the document will be sent
	 *                          to the browser (destination I).
	 *                          BLANK or omitted: "doc.pdf"
	 * @param string $dest I: send the file inline to the browser. The plug-in is used if available.
	 *                          The name given by $filename is used when one selects the "Save as"
	 *                          option on the link generating the PDF.
	 *                          D: send to the browser and force a file download with the name
	 *                          given by $filename.
	 *                          F: save to a local file with the name given by $filename (may
	 *                          include a path).
	 *                          S: return the document as a string. $filename is ignored.
	 *
	 * @link https://mpdf.github.io/reference/mpdf-functions/output.html
	 */
	protected function output_pdf( $html, $file_name, $dest = 'F' ) {
		// Should not happen but a fail-safe
		if ( empty( $file_name ) ) {
			$file_name = 'et_ticket_' . uniqid();
		}

		// If $file_name does not end in ".pdf", add it.
		if ( '.pdf' !== substr( $file_name, - 4 ) ) {
			$file_name .= '.pdf';
		}

		/**
		 * Empty the output buffer to ensure the website page's HTML is not included by accident.
		 *
		 * @link https://mpdf.github.io/what-else-can-i-do/capture-html-output.html
		 * @link https://stackoverflow.com/a/35574170/893907
		 */
		ob_clean();

		$mpdf = $this->get_mpdf( $html );
		$mpdf->Output( $file_name, $dest );
	}

	/**
	 * Converts HTML to mPDF object
	 *
	 * @see Tribe__Extension__PDF_Tickets::mpdf_lib_dir()
	 * @see mPDF::WriteHTML()
	 *
	 * @param string $html The full HTML you want converted to a PDF
	 *
	 * @return mPDF
	 */
	protected function get_mpdf( $html ) {
		require_once( $this->mpdf_lib_dir() . 'mpdf.php' );

		/**
		 * Creating and setting the PDF
		 *
		 * Reference vendor/mpdf/config.php, especially since it may not
		 * match the documentation.
		 * 'c' mode sets the mPDF Mode to use onlyCoreFonts so that we do not
		 * need to include any fonts (like the dejavu... ones) in
		 * vendor/mpdf/ttfontdata
		 *
		 * @link https://mpdf.github.io/reference/mpdf-variables/overview.html
		 * @link https://github.com/mpdf/mpdf/pull/490
		 */
		$mpdf = new mPDF( 'c' );

		$mpdf->WriteHTML( $html );

		return $mpdf;
	}

	/**
	 * Get the current URL
	 *
	 * @link https://css-tricks.com/snippets/php/get-current-page-url/
	 *
	 * @return string
	 */
	private function get_current_page_url() {
		$url = @( $_SERVER["HTTPS"] != 'on' ) ? 'http://' . $_SERVER["SERVER_NAME"] : 'https://' . $_SERVER["SERVER_NAME"];
		$url .= ( $_SERVER["SERVER_PORT"] !== '80' ) ? ":" . $_SERVER["SERVER_PORT"] : "";
		$url .= $_SERVER["REQUEST_URI"];

		return $url;
	}

	/**
	 * Get the Attendee ID from the attempted URL.
	 *
	 * Once we have the current page URL, see if it looks like one of the PDF
	 * Ticket file names, and, if it does, try to parse the Attendee ID out of
	 * it. Should only be running when hitting a 404.
	 *
	 * @see Tribe__Extension__PDF_Tickets::uploads_directory_url()
	 *
	 * @param $url
	 *
	 * @return int
	 */
	private function get_attendee_id_from_attempted_url( $url ) {
		$url = strtolower( esc_url( $url ) );

		$beginning_url_check = $this->uploads_directory_url() . 'et_';

		// bail if...
		if (
			// no URL
			empty( $url )
			// not looking for a file in Uploads that begins with 'et_'
			|| false === $this->string_starts_with( $url, $beginning_url_check )
			// not looking for a file ending in '.pdf'
			|| false === $this->string_ends_with( $_SERVER['REQUEST_URI'], '.pdf' )
		) {
			return 0;
		}

		// remove from the front
		$file_name = str_replace( $beginning_url_check, '', $url );

		// look for an integer with double underscores on both sides
		preg_match( '/__(\d+)__/', $file_name, $matches );

		// bail if not found
		if ( empty( $matches ) ) {
			return 0;
		}

		$guessed_attendee_id = absint( $matches[1] );

		if ( 0 >= $guessed_attendee_id ) {
			return 0;
		}

		$post_type = get_post_type( $guessed_attendee_id );

		if (
			empty( $post_type )
			|| false === $this->string_starts_with( $post_type, 'tribe_' )
		) {
			return 0;
		}

		return $guessed_attendee_id; // hopefully we were right!
	}

	/**
	 * Create and upload an 404'd PDF Ticket, then redirect to it
	 * now that it exists.
	 *
	 * If we attempted to load a PDF Ticket but it was not found (404), then
	 * create the PDF Ticket, upload it to the server, and reload the attempted
	 * URL, adding a query string on the end as a cache buster and so the
	 * 307 Temporary Redirect code is technically valid.
	 *
	 * @see Tribe__Extension__PDF_Tickets::get_current_page_url()
	 * @see Tribe__Extension__PDF_Tickets::get_attendee_id_from_attempted_url()
	 * @see Tribe__Extension__PDF_Tickets::do_upload_pdf()
	 */
	public function if_pdf_url_404_upload_pdf_then_reload() {
		if ( ! is_404() ) {
			return;
		}

		$url = strtolower( $this->get_current_page_url() );

		$guessed_attendee_id = $this->get_attendee_id_from_attempted_url( $url );

		if ( empty( $guessed_attendee_id ) ) {
			return;
		}

		$query_key = 'et_ticket_created';

		if ( isset ( $_GET[ $query_key ] ) ) {
			$already_retried = true;
		} else {
			$already_retried = false;
		}

		if ( true === $already_retried ) {
			return;
		}

		// if we got this far, we probably guessed correctly so let's generate the PDF
		$this->do_upload_pdf( $guessed_attendee_id, false );

		/**
		 * Redirect to retrying reloading the PDF
		 *
		 * Cache buster and technically a new URL so status code 307 Temporary Redirect applies
		 *
		 * @link https://en.wikipedia.org/wiki/List_of_HTTP_status_codes#3xx_Redirection
		 */
		$url = add_query_arg( $query_key, time() );
		wp_redirect( $url, 307 );
		exit;
	}

}