<?php

if (!defined('ABSPATH')) exit;

class Rs2010ForumShortcodes {
    private $rs2010forum = null;
    private $postObject = null;
    public $shortcodeSearchFilter = '';
    public $includeCategories = array();

    public function __construct($object) {
		$this->rs2010forum = $object;

        add_action('init', array($this, 'initialize'));
    }

    public function initialize() {
        // Register multiple shortcodes because sometimes users ignore the fact that shortcodes are case-sensitive.
        add_shortcode('forum', array($this->rs2010forum, 'forum'));
        add_shortcode('Forum', array($this->rs2010forum, 'forum'));
    }

    public function checkForShortcode($object = false) {
        $this->postObject = $object;

        // If no post-object is set, use the location.
        if (!$this->postObject && $this->rs2010forum->options['location']) {
            $this->postObject = get_post($this->rs2010forum->options['location']);
        }

        if ($this->postObject && (has_shortcode($this->postObject->post_content, 'forum') || has_shortcode($this->postObject->post_content, 'Forum'))) {
            return true;
        } else {
            return false;
        }
    }

    public function handleAttributes() {
        $atts = array();
        $pattern = get_shortcode_regex();

        if (preg_match_all('/'.$pattern.'/s', $this->postObject->post_content, $matches) && array_key_exists(2, $matches) && (in_array('forum', $matches[2]) || in_array('Forum', $matches[2]))) {
            $atts = shortcode_parse_atts($matches[3][0]);

            if (!empty($atts)) {
                // Normalize attribute keys.
                $atts = array_change_key_case((array)$atts, CASE_LOWER);

                if (!empty($atts['post']) && ctype_digit($atts['post'])) {
                    $postID = $atts['post'];
                    $this->rs2010forum->current_view = 'post';
                    $this->rs2010forum->setParents($postID, 'post');
                } else if (!empty($atts['topic']) && ctype_digit($atts['topic'])) {
                    $topicID = $atts['topic'];
                    $allowedViews = array('movetopic', 'addpost', 'editpost', 'topic', 'profile', 'history');

                    // Ensure that we are in the correct element.
                    if ($this->rs2010forum->current_topic != $topicID) {
                        $this->rs2010forum->setParents($topicID, 'topic');
                        $this->rs2010forum->current_view = 'topic';
                    } else if (!in_array($this->rs2010forum->current_view, $allowedViews)) {
                        // Ensure that we are in an allowed view.
                        $this->rs2010forum->current_view = 'topic';
                    }

                    // Configure components.
                    $this->rs2010forum->options['enable_search'] = false;
                    $this->rs2010forum->breadcrumbs->breadcrumbs_level = 1;
                } else if (!empty($atts['forum']) && ctype_digit($atts['forum'])) {
                    $forumID = $atts['forum'];
                    $allowedViews = array('forum', 'addtopic', 'movetopic', 'addpost', 'editpost', 'topic', 'search', 'subscriptions', 'profile', 'members', 'history');

                    // Ensure that we are in the correct element.
                    if ($this->rs2010forum->current_forum != $forumID && $this->rs2010forum->parent_forum != $forumID && $this->rs2010forum->current_view != 'search' && $this->rs2010forum->current_view != 'subscriptions' && $this->rs2010forum->current_view != 'profile' && $this->rs2010forum->current_view != 'members' && $this->rs2010forum->current_view != 'history') {
                        $this->rs2010forum->setParents($forumID, 'forum');
                        $this->rs2010forum->current_view = 'forum';
                    } else if (!in_array($this->rs2010forum->current_view, $allowedViews)) {
                        // Ensure that we are in an allowed view.
                        $this->rs2010forum->current_view = 'forum';
                    }

                    // Configure components.
                    $this->rs2010forum->breadcrumbs->breadcrumbs_level = ($this->rs2010forum->parent_forum != $forumID) ? 2 : 3;
                    $this->shortcodeSearchFilter = 'AND (f.id = '.$forumID.' OR f.parent_forum = '.$forumID.')';
                } else if (!empty($atts['category'])) {
                    $this->includeCategories = explode(',', $atts['category']);

                    $category_doesnt_matter_views = array('search', 'subscriptions', 'profile', 'members', 'history', 'markallread', 'unread', 'activity', 'unapproved', 'reports');

                    // Ensure that we are in the correct category or view, otherwise show overview.
                    if (!in_array($this->rs2010forum->current_category, $this->includeCategories) && !in_array($this->rs2010forum->current_view, $category_doesnt_matter_views)) {
                        $this->rs2010forum->current_category   = false;
                        $this->rs2010forum->parent_forum       = false;
                        $this->rs2010forum->current_forum      = false;
                        $this->rs2010forum->current_topic      = false;
                        $this->rs2010forum->current_post       = false;
                        $this->rs2010forum->current_view       = 'overview';
                    }
                }
            }
        }
    }

    // Renders shortcodes inside of a post.
    public function render_post_shortcodes($content) {
        global $shortcode_tags;

        // Do backup of original shortcode-tags.
        $shortcode_tags_backup = $shortcode_tags;

        // Ensure that the forum-shortcodes are removed.
        unset($shortcode_tags['forum']);
        unset($shortcode_tags['Forum']);

        // Remove all shortcodes if they are not allowed inside of posts ...
        if (!$this->rs2010forum->options['allow_shortcodes']) {
            $shortcode_tags = array();

            // ... but ensure that spoilers still stay available if activated.
            if ($this->rs2010forum->options['enable_spoilers']) {
                $shortcode_tags['spoiler'] = $shortcode_tags_backup['spoiler'];
            }
        }

        // Apply custom shortcodes.
        $shortcode_tags = apply_filters('rs2010forum_filter_post_shortcodes', $shortcode_tags);

        // Render shortcodes.
        $content = do_shortcode($content);

        // Restore original shortcode-tags.
        $shortcode_tags = $shortcode_tags_backup;

        return $content;
    }
}
