<?php
/*
Plugin Name: Correct Audio/Video Uploads
Version: 1.1
Plugin URI: https://core.trac.wordpress.org/ticket/40085
Description: Restores the ability to upload audio & video files in WordPress 3.7.19, 3.8.19, 3.9.17, 4.0.16, 4.1.16, 4.2.13, 4.3.9. Corrects thumbnail meta data for audio & video uploads in 4.4.8, 4.5.7, 4.6.4, 4.7.3. Please remove the plugin once the next minor WordPress update is available!
Author: Sergey Biryukov
Author URI: http://profiles.wordpress.org/sergeybiryukov/
Text Domain: correct-audio-video-uploads
*/

class Correct_Audio_Video_Uploads {

	function __construct() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

		add_filter( 'wp_generate_attachment_metadata', array( $this, 'correct_attachment_metadata' ), 10, 2 );
	}

	function load_textdomain() {
		load_plugin_textdomain( 'correct-audio-video-uploads' );
	}

	/**
	 * Reconstruct thumbnail meta data for audio and video uploads.
	 *
	 * Based on wp_generate_attachment_metadata().
	 *
	 * @param array $metadata      An array of attachment meta data.
	 * @param int   $attachment_id Current attachment ID.
	 * @return array Attachment meta data.
	 */
	function correct_attachment_metadata( $metadata, $attachment_id ) {
		$attachment = get_post( $attachment_id );
		$file       = get_attached_file( $attachment_id );
		$support    = false;

		if ( wp_attachment_is( 'video', $attachment ) ) {
			$metadata           = $this->wp_read_video_metadata( $file );
			$corrupted_metadata = wp_read_video_metadata( $file );

			$support = current_theme_supports( 'post-thumbnails', 'attachment:video' ) || post_type_supports( 'attachment:video', 'thumbnail' );
		} elseif ( wp_attachment_is( 'audio', $attachment ) ) {
			$metadata           = $this->wp_read_audio_metadata( $file );
			$corrupted_metadata = wp_read_audio_metadata( $file );

			$support = current_theme_supports( 'post-thumbnails', 'attachment:audio' ) || post_type_supports( 'attachment:audio', 'thumbnail' );
		}

		if ( $support && ! empty( $metadata['image']['data'] ) ) {
			// Check for existing corrupted cover.
			$hash = md5( $corrupted_metadata['image']['data'] );
			$posts = get_posts( array(
				'fields' => 'ids',
				'post_type' => 'attachment',
				'post_mime_type' => $metadata['image']['mime'],
				'post_status' => 'inherit',
				'posts_per_page' => 1,
				'meta_key' => '_cover_hash',
				'meta_value' => $hash
			) );
			$corrupted_image_exists = reset( $posts );

			if ( ! empty( $corrupted_image_exists ) ) {
				wp_delete_attachment( $corrupted_image_exists, true );
			}

			// Check for existing correct cover.
			$hash = md5( $metadata['image']['data'] );
			$posts = get_posts( array(
				'fields' => 'ids',
				'post_type' => 'attachment',
				'post_mime_type' => $metadata['image']['mime'],
				'post_status' => 'inherit',
				'posts_per_page' => 1,
				'meta_key' => '_cover_hash',
				'meta_value' => $hash
			) );
			$correct_image_exists = reset( $posts );

			if ( ! empty( $correct_image_exists ) ) {
				update_post_meta( $attachment_id, '_thumbnail_id', $correct_image_exists );
			} else {
				$ext = '.jpg';
				switch ( $metadata['image']['mime'] ) {
				case 'image/gif':
					$ext = '.gif';
					break;
				case 'image/png':
					$ext = '.png';
					break;
				}

				$basename = str_replace( '.', '-', basename( $file ) ) . '-image' . $ext;
				$uploaded = wp_upload_bits( $basename, '', $metadata['image']['data'] );

				if ( false === $uploaded['error'] ) {
					$image_attachment = array(
						'post_mime_type' => $metadata['image']['mime'],
						'post_type' => 'attachment',
						'post_content' => '',
					);

					/**
					 * Filters the parameters for the attachment thumbnail creation.
					 *
					 * @since 3.9.0
					 *
					 * @param array $image_attachment An array of parameters to create the thumbnail.
					 * @param array $metadata         Current attachment metadata.
					 * @param array $uploaded         An array containing the thumbnail path and url.
					 */
					$image_attachment = apply_filters( 'attachment_thumbnail_args', $image_attachment, $metadata, $uploaded );

					$sub_attachment_id = wp_insert_attachment( $image_attachment, $uploaded['file'] );

					add_post_meta( $sub_attachment_id, '_cover_hash', $hash );

					$attach_data = wp_generate_attachment_metadata( $sub_attachment_id, $uploaded['file'] );
					wp_update_attachment_metadata( $sub_attachment_id, $attach_data );

					update_post_meta( $attachment_id, '_thumbnail_id', $sub_attachment_id );
				}
			}
		}

		// Remove the blob of binary data from the array.
		if ( $metadata ) {
			unset( $metadata['image']['data'] );
		}
	
		return $metadata;
	}

	/**
	 * Parse ID3v2, ID3v1, and getID3 comments to extract usable data.
	 *
	 * Based on the function with the same name; modified to run wp_kses_post()
	 * on ID3 tags.
	 *
	 * @param array $metadata An existing array with data
	 * @param array $data Data supplied by ID3 tags
	 */
	function wp_add_id3_tag_data( &$metadata, $data ) {
		foreach ( array( 'id3v2', 'id3v1' ) as $version ) {
			if ( ! empty( $data[$version]['comments'] ) ) {
				foreach ( $data[$version]['comments'] as $key => $list ) {
					if ( 'length' !== $key && ! empty( $list ) ) {
						// $metadata[$key] = reset( $list );
						$metadata[$key] = wp_kses_post( reset( $list ) );
						// Fix bug in byte stream analysis.
						if ( 'terms_of_use' === $key && 0 === strpos( $metadata[$key], 'yright notice.' ) )
							$metadata[$key] = 'Cop' . $metadata[$key];
					}
				}
				break;
			}
		}

		if ( ! empty( $data['id3v2']['APIC'] ) ) {
			$image = reset( $data['id3v2']['APIC']);
			if ( ! empty( $image['data'] ) ) {
				$metadata['image'] = array(
					'data' => $image['data'],
					'mime' => $image['image_mime'],
					'width' => $image['image_width'],
					'height' => $image['image_height']
				);
			}
		} elseif ( ! empty( $data['comments']['picture'] ) ) {
			$image = reset( $data['comments']['picture'] );
			if ( ! empty( $image['data'] ) ) {
				$metadata['image'] = array(
					'data' => $image['data'],
					'mime' => $image['image_mime']
				);
			}
		}
	}

	/**
	 * Retrieve metadata from a video file's ID3 tags.
	 *
	 * Based on the function with the same name; modified to remove overzealous
	 * wp_kses_post() call.
	 *
	 * @param string $file Path to file.
	 * @return array|bool Returns array of metadata, if found.
	 */
	function wp_read_video_metadata( $file ) {
		if ( ! file_exists( $file ) ) {
			return false;
		}

		$metadata = array();

		if ( ! defined( 'GETID3_TEMP_DIR' ) ) {
			define( 'GETID3_TEMP_DIR', get_temp_dir() );
		}

		if ( ! class_exists( 'getID3', false ) ) {
			require( ABSPATH . WPINC . '/ID3/getid3.php' );
		}
		$id3 = new getID3();
		$data = $id3->analyze( $file );

		if ( isset( $data['video']['lossless'] ) )
			$metadata['lossless'] = $data['video']['lossless'];
		if ( ! empty( $data['video']['bitrate'] ) )
			$metadata['bitrate'] = (int) $data['video']['bitrate'];
		if ( ! empty( $data['video']['bitrate_mode'] ) )
			$metadata['bitrate_mode'] = $data['video']['bitrate_mode'];
		if ( ! empty( $data['filesize'] ) )
			$metadata['filesize'] = (int) $data['filesize'];
		if ( ! empty( $data['mime_type'] ) )
			$metadata['mime_type'] = $data['mime_type'];
		if ( ! empty( $data['playtime_seconds'] ) )
			$metadata['length'] = (int) round( $data['playtime_seconds'] );
		if ( ! empty( $data['playtime_string'] ) )
			$metadata['length_formatted'] = $data['playtime_string'];
		if ( ! empty( $data['video']['resolution_x'] ) )
			$metadata['width'] = (int) $data['video']['resolution_x'];
		if ( ! empty( $data['video']['resolution_y'] ) )
			$metadata['height'] = (int) $data['video']['resolution_y'];
		if ( ! empty( $data['fileformat'] ) )
			$metadata['fileformat'] = $data['fileformat'];
		if ( ! empty( $data['video']['dataformat'] ) )
			$metadata['dataformat'] = $data['video']['dataformat'];
		if ( ! empty( $data['video']['encoder'] ) )
			$metadata['encoder'] = $data['video']['encoder'];
		if ( ! empty( $data['video']['codec'] ) )
			$metadata['codec'] = $data['video']['codec'];

		if ( ! empty( $data['audio'] ) ) {
			unset( $data['audio']['streams'] );
			$metadata['audio'] = $data['audio'];
		}

		$this->wp_add_id3_tag_data( $metadata, $data );

		// $metadata = wp_kses_post_deep( $metadata );

		return $metadata;
	}

	/**
	 * Retrieve metadata from a audio file's ID3 tags.
	 *
	 * Based on the function with the same name; modified to remove overzealous
	 * wp_kses_post() call.
	 *
	 * @param string $file Path to file.
	 * @return array|bool Returns array of metadata, if found.
	 */
	function wp_read_audio_metadata( $file ) {
		if ( ! file_exists( $file ) ) {
			return false;
		}
		$metadata = array();

		if ( ! defined( 'GETID3_TEMP_DIR' ) ) {
			define( 'GETID3_TEMP_DIR', get_temp_dir() );
		}

		if ( ! class_exists( 'getID3', false ) ) {
			require( ABSPATH . WPINC . '/ID3/getid3.php' );
		}
		$id3 = new getID3();
		$data = $id3->analyze( $file );

		if ( ! empty( $data['audio'] ) ) {
			unset( $data['audio']['streams'] );
			$metadata = $data['audio'];
		}

		if ( ! empty( $data['fileformat'] ) )
			$metadata['fileformat'] = $data['fileformat'];
		if ( ! empty( $data['filesize'] ) )
			$metadata['filesize'] = (int) $data['filesize'];
		if ( ! empty( $data['mime_type'] ) )
			$metadata['mime_type'] = $data['mime_type'];
		if ( ! empty( $data['playtime_seconds'] ) )
			$metadata['length'] = (int) round( $data['playtime_seconds'] );
		if ( ! empty( $data['playtime_string'] ) )
			$metadata['length_formatted'] = $data['playtime_string'];

		$this->wp_add_id3_tag_data( $metadata, $data );

		// $metadata = wp_kses_post_deep( $metadata );

		return $metadata;
	}


}

new Correct_Audio_Video_Uploads;

if ( ! function_exists( 'wp_kses_post_deep' ) ) :
/**
 * Navigates through an array, object, or scalar, and sanitizes content for
 * allowed HTML tags for post content.
 *
 * @since 4.4.2
 *
 * @see map_deep()
 *
 * @param mixed $data The array, object, or scalar value to inspect.
 * @return mixed The filtered content.
 */
function wp_kses_post_deep( $data ) {
	return map_deep( $data, 'wp_kses_post' );
}
endif;

if ( ! function_exists( 'map_deep' ) ) :
/**
 * Maps a function to all non-iterable elements of an array or an object.
 *
 * This is similar to `array_walk_recursive()` but acts upon objects too.
 *
 * @since 4.4.0
 *
 * @param mixed    $value    The array, object, or scalar.
 * @param callable $callback The function to map onto $value.
 * @return mixed The value with the callback applied to all non-arrays and non-objects inside it.
 */
function map_deep( $value, $callback ) {
	if ( is_array( $value ) ) {
		foreach ( $value as $index => $item ) {
			$value[ $index ] = map_deep( $item, $callback );
		}
	} elseif ( is_object( $value ) ) {
		$object_vars = get_object_vars( $value );
		foreach ( $object_vars as $property_name => $property_value ) {
			$value->$property_name = map_deep( $property_value, $callback );
		}
	} else {
		$value = call_user_func( $callback, $value );
	}

	return $value;
}
endif;
