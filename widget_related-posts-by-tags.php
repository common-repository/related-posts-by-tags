<?php
/*
Plugin Name: Related Posts By Tags Widget
Plugin URI: http://tadhg.com/wp/wordpress-plugins/related-posts-by-tag-widget/
Description: Shows list of similar posts (by tags).
Version: 1.2
Author: Tadhg O'Higgins
Author URI: http://tadhg.com/
*/
/*  Copyright 2009 Tadhg O'Higgins  (email : wp-plugins@tadhg.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/
Class GetRelatedPostsByTags {
    
    public $posts;
    public $output;
    public $options = array(
        "tags"=>FALSE,
        "mode"=>"HTML",
        "widget_args"=>array(
            "name" => "Sidebar 1",
            "id" => "sidebar-1",
            "before_widget" =>"",
            "after_widget" =>"",
            "before_title" =>"",
            "after_title" =>"",
            "widget_id" => "related_posts_by_tags",
            "widget_name" => "Related Posts"
        )
    );
    
    function __construct($post_id, $options=FALSE) {
        /*Constructor function
        
        Gets the posts and the specified output, puts those in
        instance variables $posts and $output.
        
        @param int $post_id id of the post.
        @param array $options Optional. If present, overrides the options set
            in the WordPress admin screen for the plugin. Options are:
            "tags": if we're in the loop and already have a list of the tag ids
                for the current post, they can be passed in, thus eliminating
                the need to run get_tags_outside_loop.
            "dateorder": "ASC" or "DESC", whether the ordering of the posts by
                date is chronological or reverse-chronological. Defaults to
                "DESC".
            "mode': what format the related posts are in when they're put into
                the instance $output variable. Options are:
                    "HTML": HTML output in list form. This is the default.
                    "raw": the PHP native array.
                    "JSON": JSON object.
                    "src": script tag wrapper around the JSON object.
                    "widget": WordPress sidebar widget HTML.
            "fetchlimit": the maximum number of posts that will be returned by
                the database query.
        
        */
        //Handle options:
        if ($options) {
            $this->options = array_merge($this->options, $options);
        }
        //Get the related posts:
        $this->posts = $this->related_posts($post_id, $this->options);
        
        $modefuncs = array(
            //The dict for what function is called by what mode. The awkward
            //structure is required because we're going to end up using
            //call_user_func.
            "HTML"=>array($this, 'get_html'),
            "raw"=>array($this, 'get_raw'),
            "JSON"=>array($this, 'get_json'),
            "src"=>array($this, 'get_src'),
            "widget"=>array($this, 'get_widget')
        );
        $this->output = call_user_func($modefuncs[$this->options["mode"]], $this->posts);
    }
    
    public function related_posts($post_id, $options=array()) {
        /*
        Returns an array of post objects.
        The post objects are ordered by how many tags they share with the tags
        passed in as $tags, with more tags shared resulting in a post being higher
        up in the order, and then by date.
        */
        //Get the options set by the admin screen and the database access class:
        $admin_options = get_option('widget_related_posts_by_tags');
        global $wpdb;
        //Write the passed-in options over the admin ones:
        $options = array_merge($admin_options, $options);
        //Make sure the relevant options make sense:
        if (!$options['fetchlimit'] || $options['fetchlimit'] < 1) {
            $options['fetchlimit'] = 10;
        }
        if ($options['dateorder'] != "ASC" && $options['dateorder'] != "DESC") {
            $options['dateorder'] = "DESC";
        }
        //If we were passed the right list of tags, use them, otherwise get
        //them:
        if (!$options["tags"]) {
            //If we don't have the tag info, go get it:
            $options["tags"] = self::get_tags_outside_loop($post_id);
        }
        $tags = $options["tags"];
        if (isset($this)) {
            $this->options = $options;
        }
        $tag_ids = array();
        //Get the ids we need for the main query, that is, tranlate from tag id to
        //taxonomy_id.
        foreach ($tags as &$item) {
            $taxid_query = "
                SELECT term_taxonomy_id FROM $wpdb->term_taxonomy
                WHERE term_id = " . $item->term_id . "
                AND taxonomy = 'post_tag'
                LIMIT 1;
            ";
            $taxonomy_id = $wpdb->get_var($taxid_query);
            array_push($tag_ids, $taxonomy_id);
        }
        $comma_separated = implode(",", $tag_ids);
        
        /*
        The following query is a fairly simple join of wp_posts and
        wp_term_relationships, combined with a tricky way of getting the posts
        with the most overlapping tags.
        The tricky part is mainly in the lines:
        GROUP BY $wpdb->term_relationships.object_id
        HAVING SUM(CASE WHEN $wpdb->term_relationships.term_taxonomy_id in (" . $comma_separated . ") THEN 1 END) > 0
        The inner case statement says that when the taxonomy_id is in the list,
        add one to the sum. This plus the group by allows us to determine how many
        matches there are for a single post id against the various ids in
        $comma_separated despite the fact that each match is on a separate row.
        */
        $related_tags_query = "
            SELECT $wpdb->posts.*, SUM(CASE WHEN $wpdb->term_relationships.term_taxonomy_id in (" . $comma_separated . ") THEN 1 END) AS matchnum from $wpdb->posts
            LEFT JOIN $wpdb->term_relationships ON ($wpdb->posts.ID = $wpdb->term_relationships.object_id)
            WHERE $wpdb->posts.post_status = 'publish'
            AND $wpdb->posts.post_type = 'post'
            AND $wpdb->posts.ID != " . $post_id . "
            GROUP BY $wpdb->term_relationships.object_id
            HAVING SUM(CASE WHEN $wpdb->term_relationships.term_taxonomy_id in (" . $comma_separated . ") THEN 1 END) > 0
            ORDER BY matchnum DESC, $wpdb->posts.post_date " . $options['dateorder'] . "
            LIMIT " . $options['fetchlimit'] . ";
        ";
        return $wpdb->get_results($related_tags_query);
    }
    
    function get_tags_outside_loop($post_id) {
        //Because there's no WP-native function to do this, apparently.
        global $wpdb;
        $tag_query = "
            SELECT $wpdb->term_taxonomy.* FROM $wpdb->term_taxonomy
            LEFT JOIN $wpdb->term_relationships ON ($wpdb->term_taxonomy.term_taxonomy_id = $wpdb->term_relationships.term_taxonomy_id)
            WHERE object_id = $post_id
            AND taxonomy = 'post_tag';
        ";
        return $wpdb->get_results($tag_query); 
    }
    
    function pydictget($arr, $key, $default) {
        //I just want something that does dict.get(key, default)!
        if (!array_key_exists($key, $arr)) {
            return $default;
        } else {
            return $arr[$key];
        }
        
    }
    
    function rss_list($content) {
        /* 
        Adds the list of related posts to the bottom of each post in the RSS
        feed.
        */
        //Due to ongoing problems with the_content_rss (see
        //http://core.trac.wordpress.org/ticket/8706 ) I need separate hooks for
        //RSS and non-RSS content.
        if (is_feed()) {
            $admin_options = get_option('widget_related_posts_by_tags');
            if (self::pydictget($admin_options, "showinrss", FALSE)) {
                global $post;
                $options = array(
                    "mode"=>"HTML",
                    "widget_args"=>$args
                );
                $rpbt = new GetRelatedPostsByTags($post->ID, $options);
                $rss_title = self::pydictget($admin_options, "title", "Related Posts");
                $content = sprintf("%s<h4 class='related-posts-header'>%s</h4>%s", $content, $rss_title, $rpbt->output);
            }
        }
        return $content;
    }
    
    function post_list($content) {
        /* 
        Adds the list of related posts to the bottom of each post on the site
        (that is, explicitly not in RSS feeds, as that is a separate option).
        */
        //Due to ongoing problems with the_content_rss (see
        //http://core.trac.wordpress.org/ticket/8706 ) I need separate hooks for
        //RSS and non-RSS content.
        if (!is_feed() && is_single()) {
            $admin_options = get_option('widget_related_posts_by_tags');
            if (self::pydictget($admin_options, "showatendofpost", FALSE)) {
                global $post;
                $options = array(
                    "mode"=>"HTML",
                    "widget_args"=>$args
                );
                $rpbt = new GetRelatedPostsByTags($post->ID, $options);
                $list_title = self::pydictget($admin_options, "title", "Related Posts");
                $content = sprintf("%s<h4 class='related-posts-header'>%s</h4>%s", $content, $list_title, $rpbt->output);
            }
        }
        return $content;
    }
    
    //The functions that build the various output:
    public function build_html($posts) {
        if (isset($this) && array_key_exists("dateformat", $this->options)) {
            $dateformat = $this->options["dateformat"];
        } else {
            $dateformat = "D d M Y";
        }
        $tags_html .= '<ul class="related-posts-list">';
        foreach ($posts as &$post) {
            //Show date only if we have a dateformat string:
            $timestamp = ($dateformat != "") ? '<span class="related-post-date timestamp">' . date($dateformat, strtotime($post->post_date)) . '</span>' : "";
            $tags_html .= '<li class="related-post">';
            $tags_html .= '<a href="' . get_permalink($post->ID) . '">' . $post->post_title . '</a> ' . $timestamp;
            $tags_html .= '</li>';
        }
        $tags_html .= "</ul>";
        return $tags_html;
    }
    
    public function build_raw($posts) {
        return $posts;
    }
    
    public function build_json($posts) {
        return "{ \"posts\": " . json_encode($posts) . "}";
    }
    
    public function build_src($posts, $varName="RelatedPosts") {
        return '<script type="text/javascript">var ' . $varName . ' = ' . self::build_json($posts) . ';</script>';
    }
    
    public function build_widget($posts) {
        $tags_html = $this->build_html($posts);
        extract($this->options["widget_args"]);
        $widget_html = sprintf("%s%s%s%s%s%s", $before_widget, $before_title, $this->options['title'], $after_title, $tags_html, $after_widget);
        return $widget_html;
    }
    
    //These are shortcut wrappers for the build_ functions that only works as
    //instance methods:
    public function get_html() {
        return $this->build_html($this->posts);
    }
    public function get_raw() {
        return $this->build_raw($this->posts);
    }
    public function get_json() {
        return $this->build_json($this->posts);
    }
    public function get_src() {
        return $this->build_src($this->posts);
    }
    public function get_widget() {
        return $this->build_widget($this->posts);
    }
    
    //Widget controls:
    function widget_control() {
        $default_options = array(
            'fetchlimit'  => 10,
            'title' => "Related Posts",
            'dateformat' => "D d M Y",
            'dateorder' => "DESC",
            'displaylimit' => 10,
            'showinrss' => FALSE,
            'showatendofpost' => FALSE
        );
        add_option('widget_related_posts_by_tags', $default_options, 'Related Posts By Tags');
        if ( isset($_POST["related-posts-by-tags-submit"]) ) {
            $test = "!";
            $updated_options = array();
            //Assumes that all options have a key in $default_options
            //and that the key names are the same as the form input names with
            //a 'related-posts-by-tags' prefix:
            foreach ($default_options as $key=>$value) {
                $postkey = sprintf("related-posts-by-tags-%s", $key);
                $updated_options[$key] = strip_tags(stripslashes(self::pydictget($_POST, $postkey, "")));
            }
            update_option('widget_related_posts_by_tags', $updated_options);
        }
    
        /* Get options */
        $options = array_merge($default_options, get_option('widget_related_posts_by_tags'));
        if (!$options['fetchlimit'] || $options['fetchlimit'] < 1) {
            $options['fetchlimit'] = 10;
        }
        ?>
        <?php print_r($options); ?>
            <p>
                <label for="related-posts-by-tags-title"><?php _e('Title:'); ?> <input class="widefat" id="related-posts-by-tags-title" name="related-posts-by-tags-title" type="text" value="<?php echo $options['title']; ?>" /></label>
            </p>
            <p>
                <label for="related-posts-by-tags-fetchlimit"><?php _e('Number of related posts to display:'); ?> <input style="width: 30px; text-align: center;" id="related-posts-by-tags-fetchlimit" name="related-posts-by-tags-fetchlimit" type="text" value="<?php echo $options['fetchlimit']; ?>" /></label>
            </p>
            <p>
                <label for="related-posts-by-tags-dateformat"><?php _e('Date format (blank hides date):'); ?> <input style="width: 60px; text-align: center;" id="related-posts-by-tags-dateformat" name="related-posts-by-tags-dateformat" type="text" value="<?php echo $options['dateformat']; ?>" /></label>
            </p>
            <p>
                <label for="related-posts-by-tags-showinrss"><?php _e('Show list in RSS posts:'); ?> <input id="related-posts-by-tags-showinrss" name="related-posts-by-tags-showinrss" type="checkbox" value="TRUE" <?php $chk = $options['showinrss'] ? 'checked=checked' : ''; echo($chk);?> /></label>
            </p>
            <p>
                <label for="related-posts-by-tags-showatendofpost"><?php _e('Show list at end of posts:'); ?> <input id="related-posts-by-tags-showatendofpost" name="related-posts-by-tags-showatendofpost" type="checkbox" value="TRUE" <?php $chk = $options['showatendofpost'] ? 'checked=checked' : ''; echo($chk);?> /></label>
            </p>
            <input type="hidden" id="related-posts-by-tags-submit" name="related-posts-by-tags-submit" value="1" />
        <?php
    }
    
}

//Convenience functions for calling class functions:
function wp_widget_related_posts_by_tags($args) {
    if (is_single()) {
        global $post;
        $options = array(
            "mode"=>"widget",
            "widget_args"=>$args
        );
        $rpbt = new GetRelatedPostsByTags($post->ID, $options);
        echo($rpbt->output);
    }
}

function wp_widget_related_posts_by_tags_control() {
    GetRelatedPostsByTags::widget_control();
}

function wp_widget_related_posts_by_tags_post_list_rss($content) {
    return GetRelatedPostsByTags::rss_list($content);
}

function wp_widget_related_posts_by_tags_post_list($content) {
    return GetRelatedPostsByTags::post_list($content);
}

//Widget registration function:
function wp_widget_related_posts_by_tags_register() {
	$widget_ops = array('classname' => 'related-posts', 'description' => __( 'Posts with similar tags' ) );
	if (function_exists('wp_register_sidebar_widget')) {
        wp_register_sidebar_widget('related_posts_by_tags', __('Related Posts'), 'wp_widget_related_posts_by_tags', $widget_ops);
    }
    if (function_exists('wp_register_widget_control')) {
        wp_register_widget_control('related_posts_by_tags', __('Related Posts'), 'wp_widget_related_posts_by_tags_control');
    }
}
//Widget registration hook:
add_action('widgets_init', 'wp_widget_related_posts_by_tags_register');

//Filter hooks for appending list to posts or rss feeds:
add_filter('the_content', 'wp_widget_related_posts_by_tags_post_list_rss', 100);
add_filter('the_content', 'wp_widget_related_posts_by_tags_post_list', 100);
?>
