<?php
require __DIR__ . '/includes/bootstrap.php';
if (is_logged_in()) audit('auth.logout', 'user', (int) current_user()['id']);
logout_user();
session_start();
flash('success', 'You have been logged out.');
redirect('login.php');
