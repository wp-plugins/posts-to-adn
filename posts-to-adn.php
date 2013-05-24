<?php
/*
Plugin Name: Posts to ADN
Plugin URI: http://wordpress.org/extend/plugins/posts-to-adn/
Description: Automatically posts your new blog articles to your App.net account.
Author: Maxime VALETTE
Author URI: http://maxime.sh
Version: 1.0.4
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

function ptadn_api_call($url, $params = array(), $type='GET') {

    $options = ptadn_get_options();
    $json = array();

    $params['access_token'] = $options['ptadn_token'];

    $qs = http_build_query($params, '', '&');

    if ($type == 'GET') {

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, 'https://alpha-api.app.net/stream/0/'.$url.'?'.$qs);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Posts to ADN/1.0.1 (http://wordpress.org/extend/plugins/posts-to-adn/)');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $data = curl_exec($ch);
        curl_close($ch);

        $json = json_decode($data);

    } elseif ($type == 'POST') {

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, 'https://alpha-api.app.net/stream/0/'.$url);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Posts to ADN/1.0.1 (http://wordpress.org/extend/plugins/posts-to-adn/)');
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_POST, count($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $qs);

        $data = curl_exec($ch);
        curl_close($ch);

        $json = json_decode($data);

    }

    return $json;

}

function ptadn_conf() {

    $options = ptadn_get_options();

	$updated = false;

    if ($_GET['token']) {

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

		if (isset($_POST['ptadn_disabled'])) {

            $ptadn_disabled = $_POST['ptadn_disabled'];

		} else {

            $ptadn_disabled = 0;

		}

        if (isset($_POST['ptadn_text'])) {

            $ptadn_text = $_POST['ptadn_text'];

        } else {

            $ptadn_text = '{title} {link}';

        }

        $options['ptadn_disabled'] = $ptadn_disabled;
        $options['ptadn_text'] = $ptadn_text;

		update_option('ptadn', $options);

		$updated = true;

	}

    echo '<div class="wrap">';

    if ($updated) {

        echo '<div id="message" class="updated fade"><p>Settings updated.</p></div>';

    }

    if ($options['ptadn_token']) {

        $json = ptadn_api_call('users/me');

        if (is_array($json->error) && count($json->error)) {

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

        echo '<p><img src="'.$json->data->avatar_image->url.'" width="60"></p>';

        echo '<p>You are authenticated on App.net with the username: '.$json->data->username.'</p>';
        echo '<p><a href="'.admin_url('options-general.php?page=posts-to-adn/posts-to-adn.php').'&token=reset">Disconnect from App.net</a></p>';

        echo '<form action="'.admin_url('options-general.php?page=posts-to-adn/posts-to-adn.php').'" method="post">';

        echo '<h3><label for="ptadn_text">ADN Post format:</label></h3>';
        echo '<p><input type="text" id="ptadn_text" name="ptadn_text" value="'.$options['ptadn_text'].'" style="width: 400px;" /></p>';

        echo '<p>Variables: {title} for the blog title, {link} for the permalink.</p>';

        echo '<h3>Advanced Options</h3>';

        echo '<p><input id="ptadn_disabled" name="ptadn_disabled" type="checkbox" value="1"';
        if ($options['ptadn_disabled'] == 1) echo ' checked';
        echo '/> <label for="ptadn_disabled">Disable auto posting to App.net</label></p>';

        echo '<p class="submit" style="text-align: left">';
        wp_nonce_field('ptadn', 'ptadn-admin');
        echo '<input type="submit" name="submit" value="'.__('Save').' &raquo;" /></p></form>';

    }

}

// Posts to ADN when there is a new post
function ptadn_posts_to_adn($postID) {

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

    if ($new) {

        $text = str_replace(
            array('{title}', '{link}'),
            array($post_info['postTitle'], $post_info['postLink']),
            $options['ptadn_text']
        );

        error_log('New post: '.$text);

        //ptadn_api_call('posts', array('text' => $text), 'POST');

    }

    return $postID;

}

function ptadn_post_info($postID) {

    $post = get_post($postID);

    $values = array();

    $values['id'] = $postID;
    $values['postinfo'] = $post;
    $values['authId'] = $post->post_author;

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

function ptadn_admin_notice() {

    $options = ptadn_get_options();

    if (current_user_can('manage_options') && empty($options['ptadn_token']) && !isset($_GET['token'])) {

        echo '<div class="error"><p>Warning: Your App.net account is not properly configured in the Posts to ADN plugin. <a href="'.admin_url('options-general.php?page=posts-to-adn/posts-to-adn.php').'">Update settings &rarr;</a></p></div>';

    }

}

// Admin notice
add_action('admin_notices', 'ptadn_admin_notice');

function ptadn_get_options() {

    $options = get_option('ptadn');

    if (!isset($options['ptadn_token'])) $options['ptadn_token'] = null;
    if (!isset($options['ptadn_disabled'])) $options['ptadn_disabled'] = 0;
    if (!isset($options['ptadn_text'])) $options['ptadn_text'] = '{title} {link}';

    return $options;

}