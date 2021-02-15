<?php

if (!defined('ABSPATH')) exit;

class Rs2010ForumUploads {
	private $rs2010forum = null;
	private $upload_folder = 'rs2010forum';
	private $upload_path;
	private $upload_url;
	private $upload_allowed_filetypes;

	public function __construct($object) {
		$this->rs2010forum = $object;

		add_action('init', array($this, 'initialize'));
	}

	public function initialize() {
		$this->upload_folder = apply_filters('rs2010forum_filter_upload_folder', $this->upload_folder);
		$upload_dir = wp_upload_dir();
		$this->upload_path = $upload_dir['basedir'].'/'.$this->upload_folder.'/';
		$this->upload_url = $upload_dir['baseurl'].'/'.$this->upload_folder.'/';
		$this->upload_allowed_filetypes = explode(',', $this->rs2010forum->options['allowed_filetypes']);
	}

	public function delete_post_files($post_id) {
		$path = $this->upload_path.$post_id.'/';

        if (is_dir($path)) {
            $files = array_diff(scandir($path), array('.', '..'));

            foreach ($files as $file) {
                unlink($path.$file);
            }

            rmdir($path);
        }
	}

	// Check if its allowed to upload files with those extensions.
	public function check_uploads_extension() {
		if ($this->rs2010forum->options['allow_file_uploads'] && !empty($_FILES['forumfile'])) {
			foreach ($_FILES['forumfile']['name'] as $index => $tmpName) {
				if (empty($_FILES['forumfile']['error'][$index]) && !empty($_FILES['forumfile']['name'][$index])) {
					$file_extension = strtolower(pathinfo($_FILES['forumfile']['name'][$index], PATHINFO_EXTENSION));

					if (!in_array($file_extension, $this->upload_allowed_filetypes)) {
						return false;
					}
				}
			}
		}

		return true;
	}

	// Check if its allowed to upload files with those sizes.
	public function check_uploads_size() {
		if ($this->rs2010forum->options['allow_file_uploads'] && !empty($_FILES['forumfile'])) {
			foreach ($_FILES['forumfile']['name'] as $index => $tmpName) {
				if (!empty($_FILES['forumfile']['error'][$index]) && $_FILES['forumfile']['error'][$index] == 2) {
					return false;
				} else if (empty($_FILES['forumfile']['error'][$index]) && !empty($_FILES['forumfile']['name'][$index])) {
					$maximumFileSize = (1024 * (1024 * $this->rs2010forum->options['uploads_maximum_size']));

					if ($maximumFileSize != 0 && $_FILES['forumfile']['size'][$index] > $maximumFileSize) {
						return false;
					}
				}
			}
		}

		return true;
	}

	// Generates the list of new files to upload.
	public function get_upload_list() {
		$files = array();

		if ($this->rs2010forum->options['allow_file_uploads'] && !empty($_FILES['forumfile'])) {
            foreach ($_FILES['forumfile']['name'] as $index => $tmpName) {
                if (empty($_FILES['forumfile']['error'][$index]) && !empty($_FILES['forumfile']['name'][$index])) {
					$name = sanitize_file_name(stripslashes($_FILES['forumfile']['name'][$index]));

			        if (!empty($name)) {
						$files[$index] = $name;
					}
                }
            }
        }

		return $files;
	}

	public function create_upload_folders($path) {
		if (!is_dir($this->upload_path)) {
			mkdir($this->upload_path);
		}

		if (!is_dir($path)) {
			mkdir($path);
		}
	}

	public function upload_files($post_id, $uploadList) {
		$path = $this->upload_path.$post_id.'/';
		$links = array();
		$files = $uploadList;

		// When there are files to upload, create the folders first.
        if (!empty($files)) {
            $this->create_upload_folders($path);
		}

		// Continue when the destination-folder exists.
		if (is_dir($path)) {
	        // Register existing files.
	        if (!empty($_POST['existingfile'])) {
	            foreach ($_POST['existingfile'] as $file) {
	                if (is_file($path.wp_basename($file))) {
	                    $links[] = $file;
	                }
	            }
	        }

	        // Remove deleted files.
	        if (!empty($_POST['deletefile'])) {
	            foreach ($_POST['deletefile'] as $file) {
	                if (is_file($path.wp_basename($file))) {
	                    unlink($path.wp_basename($file));
	                }
	            }
	        }

			// Upload new files.
	        if (!empty($files)) {
	            foreach($files as $index => $name) {
	                move_uploaded_file($_FILES['forumfile']['tmp_name'][$index], $path.$name);
	                $links[] = $name;
	            }
	        }

			// Remove folder if it is empty.
	        if (count(array_diff(scandir($path), array('.', '..'))) == 0) {
	            rmdir($path);
	        }
		}

        return $links;
    }

	public function show_uploaded_files($post_id, $post_uploads) {
		$path = $this->upload_path.$post_id.'/';
        $url = $this->upload_url.$post_id.'/';
        $uploads = maybe_unserialize($post_uploads);
        $uploadedFiles = '';
		$output = '';

        if (!empty($uploads) && is_dir($path)) {
			// Generate special message instead of file-list when hiding uploads for guests.
			if (!is_user_logged_in() && $this->rs2010forum->options['hide_uploads_from_guests']) {
				$uploadedFiles .= '<li>'.__('You need to login to have access to uploads.', 'rs2010-forum').'</li>';
			} else {
				foreach ($uploads as $upload) {
	                if (is_file($path.wp_basename($upload))) {
						$file_extension = strtolower(pathinfo($path.wp_basename($upload), PATHINFO_EXTENSION));
						$imageThumbnail = ($this->rs2010forum->options['uploads_show_thumbnails'] && $file_extension !== 'pdf') ? wp_get_image_editor($path.wp_basename($upload)) : false;

						$uploadedFiles .= '<li class="uploaded-file">';

						if ($imageThumbnail && !is_wp_error($imageThumbnail)) {
							$uploadedFiles .= '<a href="'.$url.utf8_uri_encode($upload).'" target="_blank"><img class="resize" src="'.$url.utf8_uri_encode($upload).'" alt="'.$upload.'"></a>';
						} else {
							$uploadedFiles .= '<a href="'.$url.utf8_uri_encode($upload).'" target="_blank">'.$upload.'</a>';
						}

						$uploadedFiles .= '</li>';
	                }
	            }
			}

			if (!empty($uploadedFiles)) {
                $output .= '<strong class="uploaded-files-title">'.__('Uploaded files:', 'rs2010-forum').'</strong>';
                $output .= '<ul>'.$uploadedFiles.'</ul>';
			}
        }

		return $output;
    }

	public function show_editor_upload_form($postObject) {
		$uploadedFilesCounter = 0;

		// Show list of uploaded files first. Also shown when uploads are disabled to manage existing files if it was enabled before.
		if ($postObject && $this->rs2010forum->current_view === 'editpost') {
			$path = $this->upload_path.$postObject->id.'/';
	        $url = $this->upload_url.$postObject->id.'/';
	        $uploads = maybe_unserialize($postObject->uploads);
	        $uploadedFiles = '';

			if (!empty($uploads) && is_dir($path)) {
				foreach ($uploads as $upload) {
	                if (is_file($path.wp_basename($upload))) {
						$uploadedFilesCounter++;
	                    $uploadedFiles .= '<li>';
	                    $uploadedFiles .= '<a href="'.$url.utf8_uri_encode($upload).'" target="_blank">'.$upload.'</a> &middot; <a data-filename="'.$upload.'" class="delete">['.__('Delete', 'rs2010-forum').']</a>';
	                    $uploadedFiles .= '<input type="hidden" name="existingfile[]" value="'.$upload.'">';
	                    $uploadedFiles .= '</li>';
	                }
	            }

				if (!empty($uploadedFiles)) {
	                echo '<div class="editor-row">';
	                	echo '<span class="row-title">'.__('Uploaded files:', 'rs2010-forum').'</span>';
	                	echo '<div class="files-to-delete"></div>';
	                	echo '<ul class="uploaded-files">'.$uploadedFiles.'</ul>';
	                echo '</div>';
	            }
			}
		}

		// Show upload controls.
        if ($this->rs2010forum->options['allow_file_uploads']) {
			// Dont show upload controls under certain conditions.
			if (!is_user_logged_in() && $this->rs2010forum->options['upload_permission'] != 'everyone') {
				return;
			} else if (!$this->rs2010forum->permissions->isModerator('current') && $this->rs2010forum->options['upload_permission'] == 'moderator') {
				return;
			}

			echo '<div class="editor-row editor-row-uploads">';
				echo '<span class="row-title">'.__('Upload Files:', 'rs2010-forum').'</span>';

				// Set maximum file size.
				if ($this->rs2010forum->options['uploads_maximum_size'] != 0) {
					echo '<input type="hidden" name="MAX_FILE_SIZE" value="'.(1024 * (1024 * $this->rs2010forum->options['uploads_maximum_size'])).'">';
				}

				$flag = 'style="display: none;"';

				if ($this->rs2010forum->options['uploads_maximum_number'] == 0 || $uploadedFilesCounter < $this->rs2010forum->options['uploads_maximum_number']) {
					$uploadedFilesCounter++;
					echo '<input type="file" name="forumfile[]"><br>';

					if ($this->rs2010forum->options['uploads_maximum_number'] == 0 || $uploadedFilesCounter < $this->rs2010forum->options['uploads_maximum_number']) {
						$flag = '';
					}
				}

				echo '<a id="add_file_link" data-maximum-number="'.$this->rs2010forum->options['uploads_maximum_number'].'" '.$flag.'>'.__('Add another file ...', 'rs2010-forum').'</a>';

				$this->show_upload_restrictions();
			echo '</div>';
		}
	}

	public function show_upload_restrictions() {
		echo '<span class="upload-hints">';

		if ($this->rs2010forum->options['uploads_maximum_number'] != 0) {
			echo __('Maximum files:', 'rs2010-forum');
			echo '&nbsp;';
			echo number_format_i18n(esc_html($this->rs2010forum->options['uploads_maximum_number']));
			echo '&nbsp;&middot;&nbsp;';
		}

		if ($this->rs2010forum->options['uploads_maximum_size'] != 0) {
			echo __('Maximum file size:', 'rs2010-forum');
			echo '&nbsp;';
			echo number_format_i18n(esc_html($this->rs2010forum->options['uploads_maximum_size'])).' MB';
			echo '&nbsp;&middot;&nbsp;';
		}

		echo __('Allowed file types:', 'rs2010-forum');
		echo '&nbsp;';
		echo esc_html($this->rs2010forum->options['allowed_filetypes']);

		echo '</span>';
	}
}
