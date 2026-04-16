<?php
session_start();
require 'Connection/Config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$page_title = "Notifications";

// Include sidebar early to handle role-based redirects before any HTML output
ob_start();
include 'partials/sidebar.php';
$sidebar_content = ob_get_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - HR2 MerchFlow</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="Css/notifications.css?v=<?= time() ?>">
</head>
<body>
    <div class="app-container">
        <?= $sidebar_content ?>
        <div class="main-content">
            <?php include 'partials/nav.php'; ?>

            <div class="notifications-page">
                <div class="notifications-header">
                    <div class="notifications-title">
                        <h1><i class="fas fa-bell"></i> Notifications</h1>
                        <span class="badge" id="totalUnread">0</span>
                    </div>
                    <div class="notifications-actions">
                        <button class="btn btn-outline" id="markAllRead">
                            <i class="fas fa-check-double"></i>
                            Mark all read
                        </button>
                        <button class="btn btn-danger" id="deleteAll">
                            <i class="fas fa-trash"></i>
                            Clear all
                        </button>
                    </div>
                </div>

                <div class="notifications-filters">
                    <button class="filter-btn active" data-filter="all">
                        <i class="fas fa-inbox"></i>
                        All
                    </button>
                    <button class="filter-btn" data-filter="unread">
                        <i class="fas fa-circle"></i>
                        Unread
                        <span class="count" id="unreadCount">0</span>
                    </button>
                    <button class="filter-btn" data-filter="login">
                        <i class="fas fa-sign-in-alt"></i>
                        Login
                    </button>
                    <button class="filter-btn" data-filter="security">
                        <i class="fas fa-shield-alt"></i>
                        Security
                    </button>
                    <button class="filter-btn" data-filter="system">
                        <i class="fas fa-cog"></i>
                        System
                    </button>
                    <button class="filter-btn" data-filter="evaluation">
                        <i class="fas fa-clipboard-check"></i>
                        Evaluation
                    </button>
                </div>

                <div class="notifications-list" id="notificationsList">
                    <div class="loading">
                        <div class="spinner"></div>
                        <p>Loading notifications...</p>
                    </div>
                </div>

                <div class="pagination" id="pagination"></div>
            </div>
        </div>
    </div>

    <script>
        // Configuration
        const ITEMS_PER_PAGE = 15;
        let currentPage = 1;
        let currentFilter = 'all';
        let allNotifications = [];

        // Icon and type mappings
        function getNotificationIcon(type) {
            const icons = {
                'login': 'fa-sign-in-alt',
                'logout': 'fa-sign-out-alt',
                'security': 'fa-shield-alt',
                'system': 'fa-cog',
                'alert': 'fa-exclamation-triangle',
                'warning': 'fa-exclamation-circle',
                'success': 'fa-check-circle',
                'info': 'fa-info-circle',
                'evaluation': 'fa-clipboard-check',
                'course': 'fa-graduation-cap',
                'user': 'fa-user',
                'message': 'fa-envelope',
                'update': 'fa-sync-alt',
                'error': 'fa-times-circle',
                'default': 'fa-bell'
            };
            return icons[type] || icons['default'];
        }

        function getIconClass(type) {
            const classes = {
                'login': 'type-login',
                'logout': 'type-login',
                'security': 'type-security',
                'system': 'type-system',
                'alert': 'type-alert',
                'warning': 'type-warning',
                'success': 'type-success',
                'info': 'type-info',
                'evaluation': 'type-evaluation',
                'course': 'type-course',
                'error': 'type-error',
                'default': 'type-default'
            };
            return classes[type] || classes['default'];
        }

        function getTypeLabel(type) {
            const labels = {
                'login': 'Login Activity',
                'logout': 'Session Ended',
                'security': 'Security Alert',
                'system': 'System Update',
                'alert': 'Alert',
                'warning': 'Warning',
                'success': 'Success',
                'info': 'Information',
                'evaluation': 'Evaluation',
                'course': 'Training',
                'error': 'Error',
                'default': 'Notification'
            };
            return labels[type] || labels['default'];
        }

        function formatTimeAgo(dateString) {
            const date = new Date(dateString);
            const seconds = Math.floor((Date.now() - date.getTime()) / 1000);
            
            if (seconds < 10) return 'Just now';
            if (seconds < 60) return `${seconds}s ago`;
            
            const minutes = Math.floor(seconds / 60);
            if (minutes < 60) return `${minutes}m ago`;
            
            const hours = Math.floor(minutes / 60);
            if (hours < 24) return `${hours}h ago`;
            
            const days = Math.floor(hours / 24);
            if (days < 7) return `${days}d ago`;
            
            return date.toLocaleDateString('en-US', { 
                month: 'short', 
                day: 'numeric',
                year: date.getFullYear() !== new Date().getFullYear() ? 'numeric' : undefined
            });
        }

        // Load notifications
        async function loadNotifications() {
            try {
                const res = await fetch('get_notifications.php?limit=100', { cache: 'no-store' });
                const data = await res.json();
                
                allNotifications = data.items || [];
                
                // Update counts
                const unread = data.unread || 0;
                document.getElementById('totalUnread').textContent = unread;
                document.getElementById('unreadCount').textContent = unread;
                
                renderNotifications();
            } catch (err) {
                console.error('Failed to load notifications:', err);
                document.getElementById('notificationsList').innerHTML = `
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="empty-title">Error Loading Notifications</div>
                        <div class="empty-text">Unable to load notifications. Please try again later.</div>
                    </div>
                `;
            }
        }

        // Filter notifications
        function filterNotifications(items, filter) {
            if (filter === 'all') return items;
            if (filter === 'unread') return items.filter(i => !i.is_read);
            return items.filter(i => i.type === filter);
        }

        // Render notifications
        function renderNotifications() {
            const filtered = filterNotifications(allNotifications, currentFilter);
            const totalPages = Math.ceil(filtered.length / ITEMS_PER_PAGE);
            const start = (currentPage - 1) * ITEMS_PER_PAGE;
            const end = start + ITEMS_PER_PAGE;
            const pageItems = filtered.slice(start, end);

            const container = document.getElementById('notificationsList');

            if (pageItems.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="fas fa-bell-slash"></i>
                        </div>
                        <div class="empty-title">No Notifications</div>
                        <div class="empty-text">
                            ${currentFilter === 'all' 
                                ? "You're all caught up! Check back later for new notifications." 
                                : `No ${currentFilter} notifications found.`}
                        </div>
                    </div>
                `;
                document.getElementById('pagination').innerHTML = '';
                return;
            }

            container.innerHTML = pageItems.map(item => `
                <div class="notification-item ${item.is_read ? '' : 'unread'}" data-id="${item.id}">
                    <div class="notification-icon ${getIconClass(item.type)}">
                        <i class="fas ${getNotificationIcon(item.type)}"></i>
                    </div>
                    <div class="notification-content">
                        <div class="notification-header">
                            <span class="notification-type">${getTypeLabel(item.type)}</span>
                            ${!item.is_read ? '<span class="notification-badge new">New</span>' : ''}
                        </div>
                        <div class="notification-message">${item.message}</div>
                        <div class="notification-meta">
                            <span><i class="far fa-clock"></i> ${formatTimeAgo(item.created_at)}</span>
                        </div>
                    </div>
                    <div class="notification-actions">
                        ${!item.is_read ? `
                            <button class="action-btn mark-read" title="Mark as read">
                                <i class="fas fa-check"></i>
                            </button>
                        ` : ''}
                        <button class="action-btn delete" title="Delete">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            `).join('');

            // Render pagination
            renderPagination(totalPages);
            
            // Attach handlers
            attachHandlers();
        }

        // Render pagination
        function renderPagination(totalPages) {
            if (totalPages <= 1) {
                document.getElementById('pagination').innerHTML = '';
                return;
            }

            let html = '';
            
            // Previous button
            html += `<button class="page-btn" ${currentPage === 1 ? 'disabled' : ''} data-page="${currentPage - 1}">
                <i class="fas fa-chevron-left"></i>
            </button>`;

            // Page numbers
            for (let i = 1; i <= totalPages; i++) {
                if (i === 1 || i === totalPages || (i >= currentPage - 1 && i <= currentPage + 1)) {
                    html += `<button class="page-btn ${i === currentPage ? 'active' : ''}" data-page="${i}">${i}</button>`;
                } else if (i === currentPage - 2 || i === currentPage + 2) {
                    html += `<span style="padding: 0 0.5rem;">...</span>`;
                }
            }

            // Next button
            html += `<button class="page-btn" ${currentPage === totalPages ? 'disabled' : ''} data-page="${currentPage + 1}">
                <i class="fas fa-chevron-right"></i>
            </button>`;

            document.getElementById('pagination').innerHTML = html;

            // Attach pagination handlers
            document.querySelectorAll('.page-btn[data-page]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const page = parseInt(btn.dataset.page);
                    if (page >= 1 && page <= totalPages) {
                        currentPage = page;
                        renderNotifications();
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                    }
                });
            });
        }

        // Attach event handlers
        function attachHandlers() {
            // Mark single as read
            document.querySelectorAll('.notification-item').forEach(item => {
                item.addEventListener('click', async (e) => {
                    if (e.target.closest('.notification-actions')) return;
                    
                    const id = item.dataset.id;
                    if (!id || !item.classList.contains('unread')) return;
                    
                    try {
                        await fetch('mark_notifications.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({ action: 'mark_one', id })
                        });
                        
                        item.classList.remove('unread');
                        item.querySelector('.notification-badge')?.remove();
                        item.querySelector('.mark-read')?.remove();
                        
                        // Update data
                        const notif = allNotifications.find(n => n.id == id);
                        if (notif) notif.is_read = 1;
                        
                        // Update counts
                        updateCounts();
                    } catch (err) {
                        console.error('Failed to mark notification:', err);
                    }
                });

                // Mark read button
                item.querySelector('.mark-read')?.addEventListener('click', async (e) => {
                    e.stopPropagation();
                    const id = item.dataset.id;
                    
                    try {
                        await fetch('mark_notifications.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({ action: 'mark_one', id })
                        });
                        
                        item.classList.remove('unread');
                        item.querySelector('.notification-badge')?.remove();
                        e.target.closest('.mark-read').remove();
                        
                        const notif = allNotifications.find(n => n.id == id);
                        if (notif) notif.is_read = 1;
                        
                        updateCounts();
                    } catch (err) {
                        console.error('Failed to mark notification:', err);
                    }
                });

                // Delete button
                item.querySelector('.delete')?.addEventListener('click', async (e) => {
                    e.stopPropagation();
                    const id = item.dataset.id;
                    
                    item.style.transform = 'translateX(100%)';
                    item.style.opacity = '0';
                    item.style.transition = 'all 0.3s ease';
                    
                    setTimeout(async () => {
                        try {
                            await fetch('mark_notifications.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: new URLSearchParams({ action: 'delete', id })
                            });
                            
                            allNotifications = allNotifications.filter(n => n.id != id);
                            renderNotifications();
                            updateCounts();
                        } catch (err) {
                            console.error('Failed to delete notification:', err);
                            item.style.transform = '';
                            item.style.opacity = '';
                        }
                    }, 300);
                });
            });
        }

        // Update counts
        function updateCounts() {
            const unread = allNotifications.filter(n => !n.is_read).length;
            document.getElementById('totalUnread').textContent = unread;
            document.getElementById('unreadCount').textContent = unread;
        }

        // Filter buttons
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                currentFilter = btn.dataset.filter;
                currentPage = 1;
                renderNotifications();
            });
        });

        // Mark all read
        document.getElementById('markAllRead').addEventListener('click', async () => {
            if (!confirm('Mark all notifications as read?')) return;
            
            try {
                await fetch('mark_notifications.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: 'mark_all' })
                });
                
                allNotifications.forEach(n => n.is_read = 1);
                renderNotifications();
                updateCounts();
            } catch (err) {
                console.error('Failed to mark all as read:', err);
            }
        });

        // Delete all
        document.getElementById('deleteAll').addEventListener('click', async () => {
            if (!confirm('Delete all notifications? This cannot be undone.')) return;
            
            try {
                await fetch('mark_notifications.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: 'delete_all' })
                });
                
                allNotifications = [];
                renderNotifications();
                updateCounts();
            } catch (err) {
                console.error('Failed to delete all:', err);
            }
        });

        // Initial load
        loadNotifications();

        // Auto refresh every 30 seconds
        setInterval(loadNotifications, 30000);
    </script>
</body>
</html>
