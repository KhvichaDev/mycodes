<?php
// Filename: fix-wpcode-last-snippet.php
define('WP_USE_THEMES', false);
require_once __DIR__ . '/wp-load.php';

if (!function_exists('wp_delete_post')) {
    require_once ABSPATH . 'wp-admin/includes/post.php';
}

global $wpdb;
$messages = [];

// 1. Get latest wpcode post
$last_post = $wpdb->get_row("
    SELECT ID, post_title, post_date 
    FROM {$wpdb->prefix}posts 
    WHERE post_type = 'wpcode' 
    ORDER BY ID DESC 
    LIMIT 1
");

if (!$last_post) {
    $messages[] = ['type' => 'error', 'text' => 'No WPCode posts found.'];
} else {
    $result = wp_delete_post($last_post->ID, true);
    if ($result) {
        $messages[] = ['type' => 'success', 'text' => '✅ The latest WPCode snippet was successfully deleted.'];
        $messages[] = ['type' => 'info', 'text' => "<strong>Post ID:</strong> {$last_post->ID}"];
        $messages[] = ['type' => 'info', 'text' => "<strong>Title:</strong> " . esc_html($last_post->post_title)];
        $messages[] = ['type' => 'info', 'text' => "<strong>Date:</strong> " . esc_html($last_post->post_date)];
    } else {
        $messages[] = ['type' => 'error', 'text' => 'Failed to delete the WPCode post.'];
    }
}

// 2. Delete wpcode_snippets option
if (get_option('wpcode_snippets') !== false) {
    delete_option('wpcode_snippets');
    $messages[] = ['type' => 'success', 'text' => "<code>wpcode_snippets</code> option was deleted."];
} else {
    $messages[] = ['type' => 'info', 'text' => "<code>wpcode_snippets</code> option not found."];
}

// 3. Flush cache
wp_cache_flush();
$messages[] = ['type' => 'notice', 'text' => 'Cache has been flushed.'];
$messages[] = ['type' => 'warning', 'text' => 'Please delete this file: <code>fix-wpcode-last-snippet.php</code>'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>WPCode Cleanup</title>
  <style>
    body {
      background: #f5f5f5;
      font-family: sans-serif;
      padding: 30px;
      max-width: 800px;
      margin: auto;
    }
    .message {
      border-radius: 6px;
      padding: 14px 20px;
      margin: 15px 0;
      font-size: 15px;
      border-left: 6px solid;
    }
    .success { background: #e8f5e9; border-color: #388e3c; color: #2e7d32; }
    .error   { background: #ffebee; border-color: #d32f2f; color: #b71c1c; }
    .info    { background: #e3f2fd; border-color: #1976d2; color: #0d47a1; }
    .notice  { background: #fff3cd; border-color: #ffc107; color: #856404; }
    .warning { background: #fff8e1; border-color: #ffa000; color: #ff6f00; }
    code {
      background: #eee;
      padding: 2px 6px;
      border-radius: 4px;
    }
    h1 {
      color: #0073aa;
    }
  </style>
</head>
<body>
  <h1>🛠 WPCode Snippet Cleanup</h1>
  <?php foreach ($messages as $msg): ?>
    <div class="message <?= $msg['type'] ?>">
      <?= $msg['text'] ?>
    </div>
  <?php endforeach; ?>
</body>
</html>
