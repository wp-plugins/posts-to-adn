<?php
/*
Plugin Name: Posts to ADN
Plugin URI: http://wordpress.org/extend/plugins/posts-to-adn/
Description: Automatically posts your new blog articles to your App.net account.
Author: Maxime VALETTE
Author URI: http://maxime.sh
Version: 1.3.1
*/

add_action('admin_menu', 'ptadn_config_page');

function ptadn_config_page() {

	if (function_exists('add_submenu_page')) {

        add_submenu_page('options-general.php',
            'Posts to ADN',
            'Posts to ADN',
            'manage_options', __FILE__, 'ptadn_conf');

    }

}

function ptadn_api_call($url, $params = array(), $type='GET', $jsonContent = null) {

    $options = ptadn_get_options();
    $json = array();

    if ($type == 'GET') {

        $params['access_token'] = $options['ptadn_token'];

        $qs = http_build_query($params, '', '&');

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, 'https://alpha-api.app.net/stream/0/'.$url.'?'.$qs);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Posts to ADN/1.3.1 (http://wordpress.org/extend/plugins/posts-to-adn/)');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $data = curl_exec($ch);
        curl_close($ch);

        $json = json_decode($data);

    } elseif ($type == 'POST') {

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, 'https://alpha-api.app.net/stream/0/'.$url);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Posts to ADN/1.3.1 (http://wordpress.org/extend/plugins/posts-to-adn/)');
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_POST, count($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        if (!empty($jsonContent)) {

            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer '.$options['ptadn_token'], 'Content-type: application/json'));
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonContent);

        } else {

            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer '.$options['ptadn_token']));
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);

        }

        $data = curl_exec($ch);
        curl_close($ch);

        $json = json_decode($data);

    }

    if (isset($json->meta->error_message) && !empty($json->meta->error_message)) {

        $options['ptadn_error'] = $json->meta->error_message;

        update_option('ptadn', $options);

    }

    if ($_SERVER['SERVER_NAME'] == 'wordpress.lan') {

        error_log('API: '.$data);

    }

    return $json;

}

function ptadn_conf() {

    $options = ptadn_get_options();

	$updated = false;

    if (isset($_GET['clear_error']) && $_GET['clear_error'] == '1') {

        $options['ptadn_error'] = null;

        update_option('ptadn', $options);

        $updated = true;

    }

    if (isset($_GET['delete_schedule'])) {

        $cron = _get_cron_array();

        foreach ($cron as $timestamp => $cronhooks) {
            foreach ((array) $cronhooks as $hook => $events) {
                foreach ((array) $events as $key => $event) {

                    if ($_GET['delete_schedule'] == $key) {

                        wp_unschedule_event($timestamp, 'ptadn_event', $event['args']);

                    }

                }
            }
        }

        $updated = true;

    }

    if (isset($_GET['bitly_token']) && !empty($_GET['bitly_token'])) {

        if ($_GET['bitly_token'] == 'reset') {

            $options['ptadn_bitly_token'] = null;
            $options['ptadn_bitly_login'] = null;

        } else {

            $options['ptadn_bitly_token'] = $_GET['bitly_token'];
            $options['ptadn_bitly_login'] = $_GET['bitly_login'];

        }

        update_option('ptadn', $options);

        $updated = true;

    }

    if (isset($_GET['token']) && !empty($_GET['token'])) {

        if ($_GET['token'] == 'reset') {

            $options['ptadn_token'] = null;

        } else {

            $options['ptadn_token'] = $_GET['token'];

        }

        update_option('ptadn', $options);

        $updated = true;

    }

	if (isset($_POST['submit'])) {

		check_admin_referer('ptadn', 'ptadn-admin');

        if (isset($_POST['ptadn_thumbnail'])) {

            $ptadn_thumbnail = $_POST['ptadn_thumbnail'];

        } else {

            $ptadn_thumbnail = 0;

        }

		if (isset($_POST['ptadn_disabled'])) {

            $ptadn_disabled = $_POST['ptadn_disabled'];

		} else {

            $ptadn_disabled = 0;

		}

        if (isset($_POST['ptadn_length'])) {

            $ptadn_length = (int) $_POST['ptadn_length'];

        } else {

            $ptadn_length = 100;

        }

        if (isset($_POST['ptadn_text'])) {

            $ptadn_text = $_POST['ptadn_text'];

        } else {

            $ptadn_text = '{title} {link}';

        }

        if (is_numeric($_POST['ptadn_delay_days']) && is_numeric($_POST['ptadn_delay_hours']) && is_numeric($_POST['ptadn_delay_minutes'])) {

            $ptadn_delay = $_POST['ptadn_delay_days'] * 86400 + $_POST['ptadn_delay_hours'] * 3600 + $_POST['ptadn_delay_minutes'] * 60;

        } else {

            $ptadn_delay = 0;

        }

        $options['ptadn_thumbnail'] = $ptadn_thumbnail;
        $options['ptadn_disabled'] = $ptadn_disabled;
        $options['ptadn_text'] = $ptadn_text;
        $options['ptadn_length'] = $ptadn_length;
        $options['ptadn_delay'] = $ptadn_delay;

		update_option('ptadn', $options);

		$updated = true;

	}

    echo '<div class="wrap">';

    if ($updated) {

        echo '<div id="message" class="updated fade"><p>Settings updated.</p></div>';

    }

    if ($options['ptadn_token']) {

        $json = ptadn_api_call('users/me');

        if (isset($json->error) && is_array($json->error) && count($json->error)) {

            echo '<div id="message" class="error"><p>';
            echo 'There was something wrong with your App.net authentication. Please retry.';
            echo "</p></div>";

            $options['ptadn_token'] = null;

            update_option('ptadn', $options);

        }

    }

    echo '<h2>Posts to ADN Settings</h2>';

    if (empty($options['ptadn_token'])) {

        $params = array(
            'redirect_uri' => admin_url('options-general.php?page=posts-to-adn/posts-to-adn.php')
        );

        $auth_url = 'http://maxime.sh/triggers/adn.php?'.http_build_query($params);

        echo '<p><a href="'.$auth_url.'">Connect your App.net account</a></p>';

    } else {

        $delayDays = $delayHours = $delayMinutes = 0;

        if ($options['ptadn_delay'] > 0) {

            $delayDays = floor($options['ptadn_delay']/86400);
            $delayHours = floor(($options['ptadn_delay']-$delayDays*86400)/3600);
            $delayMinutes = floor(($options['ptadn_delay']-$delayDays*86400-$delayHours*3600)/60);

        }

        echo '<p><img src="'.$json->data->avatar_image->url.'" width="60"></p>';

        echo '<p>You are authenticated on App.net with the username: '.$json->data->username.'</p>';
        echo '<p><a href="'.admin_url('options-general.php?page=posts-to-adn/posts-to-adn.php').'&token=reset">Disconnect from App.net</a></p>';

        echo '<form action="'.admin_url('options-general.php?page=posts-to-adn/posts-to-adn.php').'" method="post">';

        echo '<h3><label for="ptadn_text">ADN Post format</label></h3>';
        echo '<p><textarea id="ptadn_text" name="ptadn_text" style="width: 400px; resize: vertical; height: 100px;">'.$options['ptadn_text'].'</textarea></p>';

        echo '<h3>Variables</h3>';

        echo '<ul><li>{title} for the blog title<li>{link} for the permalink<li>{author} for the author<li>{excerpt} for the first words of your post<li>{tags} for the tags of your article (with a #)</ul>';

        echo '<p>You can also use {linkedTitle} instead of {title} and {link} in order to use the link entity feature of App.net.</p>';

        echo '<h3>Advanced Options</h3>';

        echo '<p><input id="ptadn_thumbnail" name="ptadn_thumbnail" type="checkbox" value="1"';
        if ($options['ptadn_thumbnail'] == 1) echo ' checked';
        echo '/> <label for="ptadn_thumbnail">Also send the Featured Image for the post if there is one</label></p>';

        echo '<p><label for="ptadn_length">Excerpt length:</label> <input type="text" style="width: 50px; text-align: center;" name="ptadn_length" id="ptadn_length" value="'.$options['ptadn_length'].'" /> characters.</p>';

        echo '<p>Bit.ly URL shortening: ';

        if (is_null($options['ptadn_bitly_login'])) {

            $params = array(
                'redirect_uri' => admin_url('options-general.php?page=posts-to-adn/posts-to-adn.php')
            );

            echo '<a href="http://maxime.sh/triggers/bitly.php?'.http_build_query($params).'">Connect your Bit.ly account</a> &rarr;</p>';

        } else {

            echo 'Currently connected with '.$options['ptadn_bitly_login'].' — <a href="'.admin_url('options-general.php?page=posts-to-adn/posts-to-adn.php').'&bitly_token=reset">Disconnect</a></p>';

        }

        echo '</p>';

        echo '<p><label>Delay the ADN post:</label> <input type="text" style="width: 50px; text-align: center;" name="ptadn_delay_days" value="'.$delayDays.'" /> days,';
        echo ' <input type="text" style="width: 50px; text-align: center;" name="ptadn_delay_hours" value="'.$delayHours.'" /> hours,';
        echo ' <input type="text" style="width: 50px; text-align: center;" name="ptadn_delay_minutes" value="'.$delayMinutes.'" /> minutes.</p>';

        echo '<p><input id="ptadn_disabled" name="ptadn_disabled" type="checkbox" value="1"';
        if ($options['ptadn_disabled'] == 1) echo ' checked';
        echo '/> <label for="ptadn_disabled">Disable auto posting to App.net</label></p>';

        echo '<p class="submit" style="text-align: left">';
        wp_nonce_field('ptadn', 'ptadn-admin');
        echo '<input type="submit" name="submit" value="'.__('Save').' &raquo;" /></p></form>';

        $cron = _get_cron_array();

        foreach ($cron as $timestamp => $cronhooks) {
            foreach ((array) $cronhooks as $hook => $events) {
                if ($hook != 'ptadn_event') {
                    unset($cron[$timestamp][$hook]);
                    continue;
                }
                foreach ((array) $events as $key => $event) {
                    $cron[ $timestamp ][ $hook ][ $key ][ 'date' ] = date_i18n('Y/m/d \a\t g:ia', $timestamp);
                }
            }
            if (count($cron[$timestamp]) == 0) {
                unset($cron[$timestamp]);
            }
        }

        if (count($cron) > 0) {

            echo '<h3>Scheduled ADN posts</h3>';

            echo '<ul>';

            foreach ($cron as $timestamp => $cronhooks) {
                foreach ($cronhooks as $hook => $events) {
                    foreach ($events as $key => $event) {

                        $cronPost = $event['args'][0];
                        echo '<li><a href="'.$cronPost->guid.'" target="_blank">'.$cronPost->post_title.'</a>: Will be posted on '.$event['date'].' — <a href="'.admin_url('options-general.php?page=posts-to-adn/posts-to-adn.php').'&delete_schedule='.$key.'">Delete</a></li>';

                    }
                }
            }

            echo '</ul>';

        }

        echo '<h3>About the creator</h3>';

        echo '<p>Ping me on App.net: <a href="http://alpha.app.net/maximevalette" target="_blank">maximevalette</a></p>';

        echo '<p>My Bitcoin address: 1MriEUP5BVh9AY7uoHSqyGMsyyD31fWmNm</p>';

    }

}

// Posts to ADN when there is a new post
function ptadn_posts_to_adn($postID, $force=false) {

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE || wp_is_post_revision($postID) ) { return $postID; }
    if (isset($_POST['_inline_edit'])) { return $postID; }

    $options = ptadn_get_options();

    if ($options['ptadn_disabled'] == 1) { return $postID; }

    $post_info = ptadn_post_info($postID);
    $type = $post_info['postType'];

    if ($type == 'future') {

        $new = 1;

    } else {

        $new = (int) ptadn_date_compare($post_info['postModified'], $post_info['postDate']);

    }

    if ($new == 0 && (isset($_POST['edit_date']) && $_POST['edit_date'] == 1 && !isset($_POST['save']))) {

        $new = 1;

    }

    if (isset($_POST['ptadn_disable_post']) && $_POST['ptadn_disable_post'] == '1') {

        $new = 0;

    }

    $customFieldDisable = get_post_custom_values('ptadn_disable_post', $post_info['postId']);

    if (isset($customFieldDisable[0]) && $customFieldDisable[0] == '1') {

        $new = 0;

    }

    if ($new || $force) {

        if ($options['ptadn_delay'] > 0 && !$force) {

            wp_schedule_single_event(current_time('timestamp') + $options['ptadn_delay'], 'ptadn_event', array($postID, true));

            return $postID;

        }

        $url = $post_info['postLink'];

        if (!is_null($options['ptadn_bitly_token'])) {

            $ch = curl_init();

            $params = array(
                'access_token' => $options['ptadn_bitly_token'],
                'longUrl' => $url,
            );

            curl_setopt($ch, CURLOPT_URL, 'https://api-ssl.bitly.com/v3/shorten?' . http_build_query($params));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            $data = curl_exec($ch);
            curl_close($ch);

            $json = json_decode($data);

            if (is_numeric($json->status_code) && $json->status_code == 200) {
                $url = $json->data->url;
            }

        }

        $customFieldText = get_post_custom_values('ptadn_textarea', $post_info['postId']);

        if (isset($customFieldText[0]) && !empty($customFieldText[0])) {

            $text = $customFieldText[0];

        } else {

            $text = (isset($_POST['ptadn_textarea'])) ? $_POST['ptadn_textarea'] : $options['ptadn_text'];

        }

        $excerpt = (empty($post_info['postExcerpt'])) ? $post_info['postContent'] : $post_info['postExcerpt'];

        $text = str_replace(
            array('{title}', '{link}', '{author}', '{excerpt}', '{tags}'),
            array($post_info['postTitle'], $url, $post_info['authorName'], ptadn_word_cut($excerpt, $options['ptadn_length']), $post_info['postHashtags']),
            $text
        );

        $jsonContent = array(
            'text' => $text
        );

        $pos = mb_strpos($text, '{linkedTitle}', 0, 'UTF-8');

        if ($pos !== false) {

            $text = str_replace('{linkedTitle}', $post_info['postTitle'], $text);

            $jsonContent = array(
                'text' => $text,
                'entities' => array(
                    'links' => array(
                        array(
                            'pos' => $pos,
                            'len' => mb_strlen($post_info['postTitle'], 'UTF-8'),
                            'url' => $url
                        )
                    )
                )
            );

        }

        if ($options['ptadn_thumbnail'] == '1') {

            $postImageId = get_post_thumbnail_id($post_info['postId']);

            if ($postImageId) {
                $thumbnail = wp_get_attachment_image_src($postImageId, 'large', false);
                if ($thumbnail) {
                    $src = $thumbnail[0];
                }
            }

            if (isset($src)) {

                preg_match('/\.([a-z]+)$/i', $src, $r);
                $fileExt = strtolower($r[1]);

                switch ($fileExt) {

                    case 'jpg':
                    case 'jpeg':
                        $fileType = 'jpeg';
                        break;

                    case 'gif':
                        $fileType = 'gif';
                        break;

                    case 'png':
                        $fileType = 'png';
                        break;

                    default:
                        $fileType = 'png';
                        break;

                }

                $ch = curl_init();
                $fileName = __DIR__ . '/' . uniqid() . '.' . $fileExt;

                curl_setopt($ch, CURLOPT_URL, $src);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

                $data = @curl_exec($ch);
                @curl_close($ch);

                if (isset($data) && !empty($data) && @file_put_contents($fileName, $data) !== false) {

                    $fileJson = ptadn_api_call('files', array(
                            'public' => true,
                            'type' => 'com.maximevalette.posts_to_adn',
                            'name' => basename($src),
                            'content' => '@' . $fileName . ';type=image/' . $fileType
                        ), 'POST');

                    if (is_string($fileJson->data->id)) {

                        $jsonContent['annotations'] = array(
                            array(
                                'type' => 'net.app.core.oembed',
                                'value' => array(
                                    '+net.app.core.file' => array(
                                        'file_id' => $fileJson->data->id,
                                        'file_token' => $fileJson->data->file_token,
                                        'format' => 'oembed'
                                    )
                                )
                            ),
                            array(
                                'type' => 'net.app.core.attachments',
                                'value' => array(
                                    '+net.app.core.file_list' => array(
                                        array(
                                            'file_id' => $fileJson->data->id,
                                            'file_token' => $fileJson->data->file_token,
                                            'format' => 'metadata'
                                        )
                                    )
                                )
                            )
                        );

                    }

                    @unlink($fileName);

                }

            }

        }

        if ($_SERVER['SERVER_NAME'] != 'wordpress.lan') {
            ptadn_api_call('posts?include_post_annotations=1', array(), 'POST', json_encode($jsonContent));
        } else {
            error_log('New post: '.json_encode($jsonContent));
        }

        delete_post_meta($post_info['postId'], 'ptadn_textarea');
        delete_post_meta($post_info['postId'], 'ptadn_disable_post');

    }

    return $postID;

}

function ptadn_save_posts_meta($postID) {

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE || wp_is_post_revision($postID) ) { return $postID; }
    if (isset($_POST['_inline_edit'])) { return $postID; }

    $options = ptadn_get_options();

    if ($options['ptadn_disabled'] == 1) { return $postID; }

    if (isset($_POST['ptadn_textarea'])) {

        if (!add_post_meta($postID, 'ptadn_textarea', $_POST['ptadn_textarea'], true)) {

            update_post_meta($postID, 'ptadn_textarea', $_POST['ptadn_textarea']);

        }

    }

    if (isset($_POST['ptadn_disable_post'])) {

        if (!add_post_meta($postID, 'ptadn_disable_post', $_POST['ptadn_disable_post'], true)) {

            update_post_meta($postID, 'ptadn_disable_post', $_POST['ptadn_disable_post']);

        }

    }

    return $postID;

}

function ptadn_post_info($postID) {

    $post = get_post($postID);
    $tags = wp_get_post_tags($post->ID);

    $values = array();

    $values['id'] = $postID;
    $values['postinfo'] = $post;
    $values['postId'] = $post->ID;
    $values['authId'] = $post->post_author;

    $info = get_userdata($values['authId']);
    $values['authorName'] = $info->display_name;

    $values['postDate'] = mysql2date("Y-m-d H:i:s", $post->post_date);
    $values['postModified'] = mysql2date("Y-m-d H:i:s", $post->post_modified);

    $thisPostTitle = stripcslashes(strip_tags($post->post_title));
    if ($thisPostTitle == '') {
        $thisPostTitle = stripcslashes(strip_tags($_POST['title']));
    }
    $values['postTitle'] = html_entity_decode($thisPostTitle, ENT_COMPAT, get_option('blog_charset'));

    $values['postLink'] = get_permalink($postID);
    $values['blogTitle'] = get_bloginfo('name');

    $values['postStatus'] = $post->post_status;
    $values['postType'] = $post->post_type;
    $values['postContent'] = trim(html_entity_decode(htmlspecialchars_decode(strip_tags($post->post_content_filtered)), ENT_COMPAT, get_option('blog_charset')));
    $values['postExcerpt'] = trim(html_entity_decode(htmlspecialchars_decode(strip_tags($post->post_excerpt)), ENT_COMPAT, get_option('blog_charset')));

    $hashtags = array();

    foreach ($tags as $tag) {

        $hashtags[] = '#' . $tag->slug;

    }

    $values['postHashtags'] = implode(' ', $hashtags);

    return $values;

}

function ptadn_date_compare($early, $late) {

    $firstdate = strtotime($early);
    $lastdate = strtotime($late);

    return ($firstdate <= $lastdate);

}

// Action when a post is published
//add_action('publish_post', 'ptadn_posts_to_adn');
//add_action('xmlrpc_publish_post', 'ptadn_posts_to_adn');
//add_action('publish_phone', 'ptadn_posts_to_adn');
add_action('new_to_publish', 'ptadn_posts_to_adn');
add_action('draft_to_publish', 'ptadn_posts_to_adn');
add_action('auto-draft_to_publish', 'ptadn_posts_to_adn');
add_action('pending_to_publish', 'ptadn_posts_to_adn');
add_action('private_to_publish', 'ptadn_posts_to_adn');
add_action('future_to_publish', 'ptadn_posts_to_adn');
add_action('save_post', 'ptadn_save_posts_meta');

add_action('ptadn_event', 'ptadn_posts_to_adn', 10, 2);

function ptadn_admin_notice() {

    $options = ptadn_get_options();

    if (current_user_can('manage_options')) {

        if (empty($options['ptadn_token']) && !isset($_GET['token'])) {

            echo '<div class="error"><p>Warning: Your App.net account is not properly configured in the Posts to ADN plugin. <a href="'.admin_url('options-general.php?page=posts-to-adn/posts-to-adn.php').'">Update settings &rarr;</a></p></div>';

        } elseif (!isset($_GET['token']) && $options['ptadn_files_scope'] === false) {

            $json = ptadn_api_call('token');

            if (is_array($json->data->scopes)) {

                if (!in_array('files', $json->data->scopes)) {

                    echo '<div class="error"><p>Warning: You should disconnect and reconnect your App.net account to authorize the Files scope. <a href="'.admin_url('options-general.php?page=posts-to-adn/posts-to-adn.php').'">Update settings &rarr;</a></p></div>';

                } else {

                    $options['ptadn_files_scope'] = true;
                    update_option('ptadn', $options);

                }

            }

        } elseif (!isset($_GET['clear_error']) && !empty($options['ptadn_error'])) {

            echo '<div class="error"><p>Warning: Your last App.net API call returned an error: '.$options['ptadn_error'].'. <a href="'.admin_url('options-general.php?page=posts-to-adn/posts-to-adn.php').'&clear_error=1">Clear and go to settings &rarr;</a></p></div>';

        }

    }

}

// Admin notice
add_action('admin_notices', 'ptadn_admin_notice');

function ptadn_get_options() {

    $options = get_option('ptadn');

    if (!isset($options['ptadn_token'])) $options['ptadn_token'] = null;
    if (!isset($options['ptadn_disabled'])) $options['ptadn_disabled'] = 0;
    if (!isset($options['ptadn_text'])) $options['ptadn_text'] = '{title} {link}';
    if (!isset($options['ptadn_length'])) $options['ptadn_length'] = 100;
    if (!isset($options['ptadn_bitly_login'])) $options['ptadn_bitly_login'] = null;
    if (!isset($options['ptadn_bitly_token'])) $options['ptadn_bitly_token'] = null;
    if (!isset($options['ptadn_delay'])) $options['ptadn_delay'] = 0;
    if (!isset($options['ptadn_error'])) $options['ptadn_error'] = null;
    if (!isset($options['ptadn_thumbnail'])) $options['ptadn_thumbnail'] = null;
    if (!isset($options['ptadn_files_scope'])) $options['ptadn_files_scope'] = false;

    return $options;

}

function ptadn_word_cut($string, $max_length) {

    if (strlen($string) <= $max_length) return $string;

    $string = mb_substr($string, 0, $max_length);
    $pos = mb_strrpos($string, " ");

    if ($pos === false) return mb_substr($string, 0, $max_length)."…";
    return mb_substr($string, 0, $pos)."…";

}

function ptadn_meta_box() {

    global $post;

    $options = ptadn_get_options();

    wp_nonce_field('ptadn', 'ptadn-meta', false, true);

    $customFieldText = get_post_custom_values('ptadn_textarea', $post->ID);
    $customFieldDisable = get_post_custom_values('ptadn_disable_post', $post->ID);

    $textarea = (isset($customFieldText[0])) ? $customFieldText[0] : $options['ptadn_text'];
    $disable = $customFieldDisable[0];

    echo '<p style="margin-bottom: 0;"><textarea style="width: 100%; height: 60px; resize: vertical;';
    echo ($disable == '1') ? ' opacity: 0.5;" disabled="disabled' : null;
    echo '" name="ptadn_textarea" id="ptadn_textarea">'.$textarea.'</textarea></p>';

    echo '<p style="margin-top: 0.5em;"><input type="checkbox" name="ptadn_disable_post" id="ptadn_disable_post" value="1" onChange="var ta = document.getElementById(\'ptadn_textarea\'); if (document.getElementById(\'ptadn_disable_post\').checked) { ta.disabled = true; ta.style[\'opacity\'] = 0.5; } else { ta.disabled = false; ta.style[\'opacity\'] = 1; }" ';
    echo ($disable == '1') ? 'checked' : null;
    echo '/>';

    echo ' <label for="ptadn_disable_post">Disable for this post</label></p>';

    echo '<p style="text-align: right;"><a href="'.admin_url('options-general.php?page=posts-to-adn/posts-to-adn.php').'">Go to Posts to ADN settings</a> &rarr;</p>';

}

function ptadn_meta($type, $context) {

    global $post;

    $screen = get_current_screen();

    if ($context == 'side' && in_array($type, array_keys(get_post_types())) && ($screen->action == 'add' || in_array($post->post_status, array('draft', 'future', 'auto-draft')))) {

        add_meta_box('ptadn', 'Posts to ADN', 'ptadn_meta_box', $type, 'side');

    }

}

add_action('do_meta_boxes', 'ptadn_meta', 20, 2);