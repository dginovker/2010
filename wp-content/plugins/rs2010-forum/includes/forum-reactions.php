<?php

if (!defined('ABSPATH')) exit;

class Rs2010ForumReactions {
    private $rs2010forum = null;
    private $reactions_list = array();
    private $post_reactions = array();

    public function __construct($object) {
        $this->rs2010forum = $object;

        // Build reactions-list.
        $this->reactions_list['down'] = array(
            'icon' => 'fas fa-thumbs-down',
            'screen_reader_text' => __('Click for thumbs down.', 'rs2010-forum')
        );

        $this->reactions_list['up'] = array(
            'icon' => 'fas fa-thumbs-up',
            'screen_reader_text' => __('Click for thumbs up.', 'rs2010-forum')
        );

        add_action('init', array($this, 'initialize'));
        add_action('rs2010forum_prepare_topic', array($this, 'prepare'));
        add_action('rs2010forum_prepare_post', array($this, 'prepare'));
        add_action('rest_api_init', array($this, 'initialize_routes'));
    }

    public function initialize() {
        if ($this->rs2010forum->options['enable_reactions']) {
            // Allow filtering of reactions.
            $this->reactions_list = apply_filters('rs2010forum_reactions', $this->reactions_list);
        }
    }

    public function prepare() {
        if ($this->rs2010forum->options['enable_reactions']) {
            // Load the reactions for the current topic.
            $this->load_reactions($this->rs2010forum->current_topic);
        }
    }

    // Loads all reactions for the given topic.
    public function load_reactions($topic_id) {
        if ($topic_id) {
            $this->post_reactions = array();

            $reactions = $this->rs2010forum->db->get_results("SELECT r.* FROM {$this->rs2010forum->tables->reactions} AS r, {$this->rs2010forum->tables->posts} AS p WHERE p.parent_id = {$topic_id} AND p.id = r.post_id;");

            foreach ($reactions as $reaction) {
                if (!isset($this->post_reactions[$reaction->post_id])) {
                    $this->post_reactions[$reaction->post_id] = array();
                }

                if (!isset($this->post_reactions[$reaction->post_id][$reaction->reaction])) {
                    $this->post_reactions[$reaction->post_id][$reaction->reaction] = array();
                }

                $this->post_reactions[$reaction->post_id][$reaction->reaction][] = $reaction->user_id;
            }
        }
    }

    public function initialize_routes() {
        if ($this->rs2010forum->options['enable_reactions']) {
            register_rest_route(
                'rs2010-forum/v1',
                '/reaction/(?P<post_id>\d+)/(?P<reaction>[a-zA-Z0-9-]+)',
                array(
                    'methods' => 'POST',
                    'callback' => array($this, 'reaction_callback'),
                    'permission_callback' => '__return_true'
                )
            );
        }
    }

    public function reaction_callback($data) {
        // Build response-array.
        $response = array();
        $response['status'] = false;

        // Ensure user is logged-in.
        if (is_user_logged_in()) {
            // Get post object.
            $post_object = $this->rs2010forum->content->get_post($data['post_id']);

            // Ensure that the current user is not the post-author.
            if ($this->rs2010forum->get_post_author($post_object->id) != get_current_user_id()) {
                // Load reactions.
                $this->load_reactions($post_object->parent_id);

                // Change reaction.
                $response['status'] = $this->reaction_change($data['post_id'], get_current_user_id(), $data['reaction'], $post_object->author_id);

                // Reload reactions.
                $this->load_reactions($post_object->parent_id);

                // Build updated reactions for posts.
                $response['data'] = $this->render_reactions($data['post_id'], $post_object->author_id);
            }
        }

        return new WP_REST_Response($response, 200);
    }

    // Renders reactions-area if the reactions-functionality is enabled.
    public function render_reactions_area($post_id, $author_id) {
        if ($this->rs2010forum->options['enable_reactions']) {
            echo '<div class="post-reactions">';
            echo $this->render_reactions($post_id, $author_id);
            echo '</div>';
        }
    }

    // Renders all reactions.
    public function render_reactions($post_id, $author_id) {
        $reactions_output = '';

        // Load existing reaction of the current user.
        $reaction_exists = $this->reaction_exists($post_id, get_current_user_id());

        // Render each reaction.
        foreach ($this->reactions_list as $key => $reaction) {
            // Set reaction-status.
            $status = ($key == $reaction_exists) ? 'reaction-active' : 'reaction-inactive';

            // Count reactions.
            $counter = (isset($this->post_reactions[$post_id][$key])) ? number_format_i18n(count($this->post_reactions[$post_id][$key])) : 0;

            // Generate reaction-HTML.
            $output = '<span class="reaction '.$key.'">';
            $output .= '<span class="reaction-icon '.$reaction['icon'].' '.$status.'">';
            $output .= '<span class="screen-reader-text">'.$reaction['screen_reader_text'].'</span>';
            $output .= '</span>';
            $output .= '<span class="reaction-number">'.$counter.'</span>';
            $output .= '</span>';

            // Wrap link around reaction if user is logged-in ...
            if (is_user_logged_in()) {
                $user_id = get_current_user_id();
                // ... and if the current user is not the post-author ...
                if ($author_id != $user_id) {
                    // ... and if the current user is not banned.
                    if (!$this->rs2010forum->permissions->isBanned($user_id)) {
                        $output = '<a data-post-id="'.$post_id.'" data-reaction="'.$key.'" href="#">'.$output.'</a>';
                    }
                }
            }

            $reactions_output .= $output;
        }

        return $reactions_output;
    }

    public function reaction_change($post_id, $user_id, $reaction, $author_id) {
        // Only add a reaction when the post exists ...
        if (!$this->rs2010forum->content->post_exists($post_id)) {
            return false;
        }

        // ... and the user is logged in ...
        if (!is_user_logged_in()) {
            return false;
        }

        // ... and the user is not banned ...
        if ($this->rs2010forum->permissions->isBanned($user_id)) {
            return false;
        }

        // ... and the reaction is not empty ...
        if (empty($reaction)) {
            return false;
        }

        // ... and when it is a valid reaction ...
        if (!isset($this->reactions_list[$reaction])) {
            return false;
        }

        // Try to get existing reaction.
        $reaction_check = $this->reaction_exists($post_id, $user_id);

        // Add reaction when there is none.
        if ($reaction_check === false) {
            $this->add_reaction($post_id, $user_id, $reaction, $author_id);
        }

        // Remove reaction when it is already set.
        if ($reaction_check === $reaction) {
            $this->remove_reaction($post_id, $user_id, $reaction);
        }

        // Update reaction when it is different.
        if ($reaction_check !== $reaction) {
            $this->update_reaction($post_id, $user_id, $reaction);
        }

        return true;
    }

    public function add_reaction($post_id, $user_id, $reaction, $author_id) {
		// Get the current time.
        $date = $this->rs2010forum->current_time();

        $this->rs2010forum->db->insert($this->rs2010forum->tables->reactions, array('post_id' => $post_id, 'user_id' => $user_id, 'reaction' => $reaction, 'author_id' => $author_id, 'datestamp' => $date), array('%d', '%d', '%s', '%d', '%s'));

        do_action('rs2010forum_after_add_reaction', $post_id, $user_id, $reaction);
    }

    public function remove_reaction($post_id, $user_id, $reaction) {

        $this->rs2010forum->db->delete($this->rs2010forum->tables->reactions, array('post_id' => $post_id, 'user_id' => $user_id, 'reaction' => $reaction), array('%d', '%d', '%s'));

        do_action('rs2010forum_after_remove_reaction', $post_id, $user_id, $reaction);
    }

    public function update_reaction($post_id, $user_id, $reaction) {
		// Get the current time.
        $date = $this->rs2010forum->current_time();

        $this->rs2010forum->db->update($this->rs2010forum->tables->reactions, array('reaction' => $reaction, 'datestamp' => $date), array('post_id' => $post_id, 'user_id' => $user_id), array('%s', '%s'), array('%d', '%d'));

        do_action('rs2010forum_after_update_reaction', $post_id, $user_id, $reaction);
    }

    // Removes all reactions from a specific post.
    public function remove_all_reactions($post_id) {
        // Remove reactions from database.
        $this->rs2010forum->db->delete($this->rs2010forum->tables->reactions, array('post_id' => $post_id), array('%d'));

        // Remove reactions from object.
        if (isset($this->post_reactions[$post_id])) {
            unset($this->post_reactions[$post_id]);
        }
    }

    public function reaction_exists($post_id, $user_id) {
        // Cancel if user-ID is 0.
        if ($user_id === 0) {
            return false;
        }

        if (isset($this->post_reactions[$post_id])) {
            foreach ($this->post_reactions[$post_id] as $reaction => $user_ids) {
                if (in_array($user_id, $user_ids)) {
                    return $reaction;
                }
            }
        }

        return false;
    }

    public function get_reactions_received($user_id, $reaction) {
        return $this->rs2010forum->db->get_var("SELECT COUNT(*) FROM {$this->rs2010forum->tables->reactions} AS r, {$this->rs2010forum->tables->posts} AS p WHERE r.post_id = p.id AND p.author_id = {$user_id} AND r.reaction = '{$reaction}';");
    }
}
