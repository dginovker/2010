<?php

if (!defined('ABSPATH')) exit;

class Rs2010ForumUnread {
    private $rs2010forum = null;
    private $user_id;
    public $excluded_items = array();

    public function __construct($object) {
        $this->rs2010forum = $object;

        add_action('rs2010forum_prepare', array($this, 'prepare_unread_status'));
        add_action('rs2010forum_prepare_markallread', array($this, 'mark_all_read'));
        add_action('rs2010forum_prepare_topic', array($this, 'mark_topic_read'));
        add_action('rs2010forum_breadcrumbs_unread', array($this, 'add_breadcrumbs'));
    }

    public function prepare_unread_status() {
        // Determine with the user ID if the user is logged in.
        $this->user_id = get_current_user_id();

        // Initialize data. For guests we use a cookie as source, otherwise use database.
        if ($this->user_id) {
            // Create database entry when it does not exist.
            if (!get_user_meta($this->user_id, 'rs2010forum_unread_cleared', true)) {
                add_user_meta($this->user_id, 'rs2010forum_unread_cleared', '1000-01-01 00:00:00');
            }

            // Get IDs of excluded topics.
            $items = get_user_meta($this->user_id, 'rs2010forum_unread_exclude', true);

            // Only add it to the exclude-list when the result is not empty because otherwise the array is converted to a string.
            if (!empty($items)) {
                $this->excluded_items = $items;
            }
        } else {
            // Create a cookie when it does not exist.
            if (!isset($_COOKIE['rs2010forum_unread_cleared'])) {
                // There is no cookie set so basically the forum has never been visited.
                setcookie('rs2010forum_unread_cleared', '1000-01-01 00:00:00', 2147483647, COOKIEPATH, COOKIE_DOMAIN);
            }

            // Get IDs of excluded topics.
            if (isset($_COOKIE['rs2010forum_unread_exclude'])) {
                $this->excluded_items = maybe_unserialize($_COOKIE['rs2010forum_unread_exclude']);
            }
        }
    }

    public function mark_all_read() {
        $current_time = $this->rs2010forum->current_time();

        if ($this->user_id) {
            update_user_meta($this->user_id, 'rs2010forum_unread_cleared', $current_time);
            delete_user_meta($this->user_id, 'rs2010forum_unread_exclude');
        } else {
            setcookie('rs2010forum_unread_cleared', $current_time, 2147483647, COOKIEPATH, COOKIE_DOMAIN);
            unset($_COOKIE['rs2010forum_unread_exclude']);
            setcookie('rs2010forum_unread_exclude', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
        }

        // Redirect to the forum overview.
        wp_redirect(html_entity_decode($this->rs2010forum->get_link('home')));
        exit;
    }

    // Marks a topic as read when an user opens it.
    public function mark_topic_read() {
        if ($this->rs2010forum->current_topic) {
            $this->excluded_items[$this->rs2010forum->current_topic] = (int) $this->rs2010forum->get_lastpost_in_topic($this->rs2010forum->current_topic)->id;

            if ($this->user_id) {
                update_user_meta($this->user_id, 'rs2010forum_unread_exclude', $this->excluded_items);
            } else {
                setcookie('rs2010forum_unread_exclude', maybe_serialize($this->excluded_items), 2147483647, COOKIEPATH, COOKIE_DOMAIN);
            }
        }
    }

    public function add_breadcrumbs() {
        $element_link = $this->rs2010forum->get_link('unread');
        $element_title = __('Unread Topics', 'rs2010-forum');
        $this->rs2010forum->breadcrumbs->add_breadcrumb($element_link, $element_title);
    }

    public function get_last_visit() {
        if ($this->user_id) {
            return get_user_meta($this->user_id, 'rs2010forum_unread_cleared', true);
        } else if (isset($_COOKIE['rs2010forum_unread_cleared'])) {
            return $_COOKIE['rs2010forum_unread_cleared'];
        } else {
            return "1000-01-01 00:00:00";
        }
    }

    public function get_status_forum($id, $topics_available) {
        // Only do the checks when there are topics available.
        if ($topics_available) {
            // Prepare list with IDs of already visited topics.
            $visited_topics = "0";

            if (!empty($this->excluded_items) && !is_string($this->excluded_items)) {
                $visited_topics = implode(',', array_keys($this->excluded_items));
            }

            // Try to find a post in a topic which has not been visited yet since last marking.
            $sql = "";

            // We need to use slightly different queries here because we cant determine if a post was created by the visiting guest.
            if ($this->user_id) {
                $sql = "SELECT p.id FROM {$this->rs2010forum->tables->forums} AS f, {$this->rs2010forum->tables->topics} AS t, {$this->rs2010forum->tables->posts} AS p WHERE (f.id = {$id} OR f.parent_forum = {$id}) AND t.parent_id = f.id AND p.parent_id = t.id AND p.parent_id NOT IN({$visited_topics}) AND p.date > '{$this->get_last_visit()}' AND t.approved = 1 AND p.author_id <> {$this->user_id} LIMIT 1;";
            } else {
                $sql = "SELECT p.id FROM {$this->rs2010forum->tables->forums} AS f, {$this->rs2010forum->tables->topics} AS t, {$this->rs2010forum->tables->posts} AS p WHERE (f.id = {$id} OR f.parent_forum = {$id}) AND t.parent_id = f.id AND p.parent_id = t.id AND p.parent_id NOT IN({$visited_topics}) AND p.date > '{$this->get_last_visit()}' AND t.approved = 1 LIMIT 1;";
            }

            $unread_check = $this->rs2010forum->db->get_results($sql);

            if (!empty($unread_check)) {
                return 'unread';
            }

            // Get last post of all topics which have been visited since last marking.
            $sql = "";

            // Again we need to use slightly different queries here because we cant determine if a post was created by the visiting guest.
            if ($this->user_id) {
                $sql = "SELECT MAX(p.id) AS max_id, p.parent_id FROM {$this->rs2010forum->tables->forums} AS f, {$this->rs2010forum->tables->topics} AS t, {$this->rs2010forum->tables->posts} AS p WHERE (f.id = {$id} OR f.parent_forum = {$id}) AND t.parent_id = f.id AND p.parent_id = t.id AND p.parent_id IN({$visited_topics}) AND t.approved = 1 AND p.author_id <> {$this->user_id} GROUP BY p.parent_id;";
            } else {
                $sql = "SELECT MAX(p.id) AS max_id, p.parent_id FROM {$this->rs2010forum->tables->forums} AS f, {$this->rs2010forum->tables->topics} AS t, {$this->rs2010forum->tables->posts} AS p WHERE (f.id = {$id} OR f.parent_forum = {$id}) AND t.parent_id = f.id AND p.parent_id = t.id AND p.parent_id IN({$visited_topics}) AND t.approved = 1 GROUP BY p.parent_id;";
            }

            $unread_check = $this->rs2010forum->db->get_results($sql);

            if (!empty($unread_check)) {
                // Check for every visited topic if it contains a newer post.
                foreach ($unread_check as $key => $last_post) {
                    if (isset($this->excluded_items[$last_post->parent_id]) && $last_post->max_id > $this->excluded_items[$last_post->parent_id]) {
                        return 'unread';
                    }
                }
            }
        }

        return 'read';
    }

    public function get_status_topic($topic_id) {
        $lastpost = $this->rs2010forum->get_lastpost_in_topic($topic_id);

        // Set empty lastpostData for loggedin user when he is the author of the last post or when topic already read.
        if ($lastpost) {
            return $this->get_status_post($lastpost->id, $lastpost->author_id, $lastpost->date, $topic_id);
        }

        return 'unread';
    }

    public function get_status_post($post_id, $post_author, $post_date, $topic_id) {
        // If post has been written before last read-marker: read
        $date_post = strtotime($post_date);
        $date_visit = strtotime($this->get_last_visit());

        if ($date_post < $date_visit) {
            return 'read';
        }

        // If post has been written from visitor: read
        if ($this->user_id && $post_author == $this->user_id) {
            return 'read';
        }

        // If the same or a newer post in this topic has already been read: read
        if (isset($this->excluded_items[$topic_id]) && $this->excluded_items[$topic_id] >= $post_id) {
            return 'read';
        }

        // In all other cases the post has not been read yet.
        return 'unread';
    }

    public function show_unread_controls() {
        echo '<div id="read-unread">';
            echo '<span class="indicator-label">';
                echo '<span class="fas fa-check"></span>';
                echo '<a href="'.$this->rs2010forum->get_link('markallread').'">'.__('Mark All Read', 'rs2010-forum').'</a>';
            echo '</span>';

            echo '<span class="indicator-label">';
                echo '<span class="fas fa-history"></span>';
                echo '<a href="'.$this->rs2010forum->get_link('unread').'">'.__('Show Unread Topics', 'rs2010-forum').'</a>';
            echo '</span>';

            echo '<div class="clear"></div>';
        echo '</div>';
    }

    function show_unread_menu() {
        echo '<div class="forum-menu">';
            echo '<a class="button button-normal" href="'.$this->rs2010forum->get_link('markallread').'">';
                echo '<span class="menu-icon fas fa-check"></span>';
                echo __('Mark All Read', 'rs2010-forum');
            echo '</a>';
        echo '</div>';
    }

    // Renders a view with all unread topics.
    public function show_unread_topics() {
        // Load unread topics.
        $unread_topics = $this->get_unread_topics();
        $unread_topics_counter = count($unread_topics);

        // Render pagination.
        $pagination_rendering = $this->rs2010forum->pagination->renderPagination('unread', false, $unread_topics_counter);

        echo '<div class="pages-and-menu">';
            echo $pagination_rendering;
            $this->show_unread_menu();
            echo '<div class="clear"></div>';
        echo '</div>';

        echo '<div class="title-element"></div>';
        echo '<div class="content-container">';

        if ($unread_topics_counter > 0) {
            $page_elements = 50;
            $page_start = $this->rs2010forum->current_page * $page_elements;
            $data_sliced = array_slice($unread_topics, $page_start, $page_elements);

            foreach ($data_sliced as $topic) {
                $topic_title = esc_html(stripslashes($topic->topic_name));

                echo '<div class="content-element unread-topic topic-normal">';
                    echo '<div class="topic-status far fa-comments unread"></div>';
                    echo '<div class="topic-name">';
                        $first_unread_post = $this->rs2010forum->content->get_first_unread_post($topic->topic_id);
                        $link = $this->rs2010forum->rewrite->get_post_link($first_unread_post->id, $first_unread_post->parent_id);
                        $human_time_diff = sprintf(__('%s ago', 'rs2010-forum'), human_time_diff(strtotime($first_unread_post->date), current_time('timestamp')));

                        if ($this->rs2010forum->is_topic_sticky($topic->topic_id)) {
                            echo '<span class="topic-icon fas fa-thumbtack"></span>';
                        }

                        if ($this->rs2010forum->is_topic_closed($topic->topic_id)) {
                            echo '<span class="topic-icon fas fa-lock"></span>';
                        }

                        if ($this->rs2010forum->polls->has_poll($topic->topic_id)) {
                            echo '<span class="topic-icon fas fa-poll-h"></span>';
                        }

                        echo '<a href="'.$link.'" title="'.$topic_title.'">'.$topic_title.'</a>';

                        echo '<small>';
                        echo __('In', 'rs2010-forum').'&nbsp;';
                        echo '<a href="'.$this->rs2010forum->rewrite->get_link('forum', $topic->forum_id).'">';
                        echo esc_html(stripslashes($topic->forum_name));
                        echo '</a>';
                        echo '&nbsp;&middot;&nbsp;';
                        echo '<i class="unread-time">'.$human_time_diff.'</i>';
                        echo '</small>';
                    echo '</div>';
                echo '</div>';
            }
        } else {
            $this->rs2010forum->render_notice(__('There are no unread topics.', 'rs2010-forum'));
        }

        echo '</div>';

        echo '<div class="pages-and-menu">';
            echo $pagination_rendering;
            $this->show_unread_menu();
            echo '<div class="clear"></div>';
        echo '</div>';
    }

    // Get all unread topics.
    public function get_unread_topics() {
        // Get accessible categories first.
        $ids_categories = $this->rs2010forum->content->get_categories_ids();

        // Load potential unread topics.
        $unread_topics = array();

        if (!empty($ids_categories)) {
            $ids_categories = implode(',', $ids_categories);

            $unread_topics = $this->rs2010forum->db->get_results("SELECT MAX(p.id) AS max_id, t.id AS topic_id, t.name AS topic_name, t.sticky, t.closed, f.id AS forum_id, f.name AS forum_name FROM {$this->rs2010forum->tables->posts} AS p LEFT JOIN {$this->rs2010forum->tables->topics} AS t ON (t.id = p.parent_id) LEFT JOIN {$this->rs2010forum->tables->forums} AS f ON (f.id = t.parent_id) WHERE f.parent_id IN ({$ids_categories}) AND p.date > '{$this->get_last_visit()}' AND t.approved = 1 GROUP BY p.parent_id ORDER BY MAX(p.id) DESC;");
        }

        // Remove read topics from that list.
        if (!empty($unread_topics) && !empty($this->excluded_items)) {
            foreach ($unread_topics as $key => $topic) {
                if (isset($this->excluded_items[$topic->topic_id]) && $topic->max_id <= $this->excluded_items[$topic->topic_id]) {
                    unset($unread_topics[$key]);
                }
            }
        }

        return $unread_topics;
    }
}