// Notification System JavaScript

// Get BASE_URL from the page (set in header or use default)
const BASE_URL = window.BASE_URL || 'http://localhost/supply/';
const NOTIFICATION_API = BASE_URL + 'api/notifications.php';

// Load notification count on page load
document.addEventListener('DOMContentLoaded', function() {
    updateNotificationCount();
    // Update notification count every 30 seconds
    setInterval(updateNotificationCount, 30000);
});

// Update notification badge count
function updateNotificationCount() {
    fetch(NOTIFICATION_API + '?action=count')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const badge = document.getElementById('notificationBadge');
                if (badge) {
                    if (data.count > 0) {
                        badge.textContent = data.count > 99 ? '99+' : data.count;
                        badge.style.display = 'inline-block';
                    } else {
                        badge.style.display = 'none';
                    }
                }
            }
        })
        .catch(error => {
            console.error('Error updating notification count:', error);
        });
}

// Open notification modal
function openNotificationModal() {
    const modal = document.getElementById('notificationModal');
    if (modal) {
        modal.style.display = 'flex';
        loadNotifications();
    }
}

// Close notification modal
function closeNotificationModal() {
    const modal = document.getElementById('notificationModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

// Load notifications
function loadNotifications() {
    const notificationList = document.getElementById('notificationList');
    if (!notificationList) return;
    
    notificationList.innerHTML = '<div style="text-align: center; padding: 40px; color: #999;"><i class="fas fa-spinner fa-spin" style="font-size: 24px;"></i><p>Loading notifications...</p></div>';
    
    fetch(NOTIFICATION_API + '?action=get')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.notifications) {
                renderNotifications(data.notifications);
                updateNotificationCount();
            } else {
                notificationList.innerHTML = '<div style="text-align: center; padding: 40px; color: #999;"><i class="fas fa-bell-slash" style="font-size: 48px; margin-bottom: 15px;"></i><p>No notifications</p></div>';
            }
        })
        .catch(error => {
            console.error('Error loading notifications:', error);
            notificationList.innerHTML = '<div style="text-align: center; padding: 40px; color: #dc3545;"><i class="fas fa-exclamation-triangle" style="font-size: 48px; margin-bottom: 15px;"></i><p>Error loading notifications</p></div>';
        });
}

// Render notifications
function renderNotifications(notifications) {
    const notificationList = document.getElementById('notificationList');
    const markAllReadBtn = document.getElementById('markAllReadBtn');
    
    if (!notificationList) return;
    
    if (notifications.length === 0) {
        notificationList.innerHTML = '<div style="text-align: center; padding: 40px; color: #999;"><i class="fas fa-bell-slash" style="font-size: 48px; margin-bottom: 15px;"></i><p>No notifications</p></div>';
        if (markAllReadBtn) markAllReadBtn.style.display = 'none';
        return;
    }
    
    const hasUnread = notifications.some(n => !n.is_read);
    if (markAllReadBtn) {
        markAllReadBtn.style.display = hasUnread ? 'inline-block' : 'none';
    }
    
    let html = '<div style="padding: 0;">';
    
    notifications.forEach(notification => {
        const isRead = notification.is_read ? 'read' : 'unread';
        const icon = getNotificationIcon(notification.type);
        const timeAgo = getTimeAgo(notification.created_at);
        
        html += `
            <div class="notification-item ${isRead}" onclick="markAsRead(${notification.id})" style="padding: 15px; border-bottom: 1px solid #eee; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.background='#f5f5f5'" onmouseout="this.style.background='white'">
                <div style="display: flex; gap: 12px;">
                    <div style="font-size: 24px; color: ${getNotificationColor()};">
                        ${icon}
                    </div>
                    <div style="flex: 1;">
                        <div style="font-weight: ${notification.is_read ? 'normal' : 'bold'}; margin-bottom: 5px;">${escapeHtml(notification.title)}</div>
                        <div style="color: #666; font-size: 14px; margin-bottom: 5px;">${escapeHtml(notification.message)}</div>
                        <div style="color: #999; font-size: 12px;">${timeAgo}</div>
                    </div>
                    ${!notification.is_read ? '<div style="width: 8px; height: 8px; background: #F2ACB9; border-radius: 50%; margin-top: 8px;"></div>' : ''}
                </div>
            </div>
        `;
    });
    
    html += '</div>';
    notificationList.innerHTML = html;
}

// Get notification icon based on type
function getNotificationIcon(type) {
    const icons = {
        'shipment_scheduled': '<i class="fas fa-shipping-fast"></i>',
        'po_created': '<i class="fas fa-shopping-cart"></i>',
        'po_approved': '<i class="fas fa-check-circle"></i>',
        'po_processed': '<i class="fas fa-box-check"></i>',
        'default': '<i class="fas fa-bell"></i>'
    };
    return icons[type] || icons['default'];
}

// Get notification color based on user role
function getNotificationColor() {
    // Check if we're on a logistics manager or supplier page
    const roleIndicator = document.querySelector('.user-role');
    if (roleIndicator) {
        const roleText = roleIndicator.textContent.toLowerCase();
        if (roleText.includes('logistics')) {
            return '#F2ACB9'; // Pink for logistics manager
        } else if (roleText.includes('supplier')) {
            return '#4CAF50'; // Green for supplier
        }
    }
    return '#F2ACB9'; // Default pink color
}

// Get time ago string
function getTimeAgo(datetime) {
    const now = new Date();
    const then = new Date(datetime);
    const diffMs = now - then;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);
    
    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins} minute${diffMins > 1 ? 's' : ''} ago`;
    if (diffHours < 24) return `${diffHours} hour${diffHours > 1 ? 's' : ''} ago`;
    if (diffDays < 7) return `${diffDays} day${diffDays > 1 ? 's' : ''} ago`;
    
    return new Date(datetime).toLocaleDateString();
}

// Mark notification as read
function markAsRead(notificationId) {
    const formData = new FormData();
    formData.append('action', 'mark_read');
    formData.append('notification_id', notificationId);
    
    fetch(NOTIFICATION_API, {
        method: 'POST',
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadNotifications();
                updateNotificationCount();
            }
        })
        .catch(error => {
            console.error('Error marking notification as read:', error);
        });
}

// Mark all notifications as read
function markAllAsRead() {
    const formData = new FormData();
    formData.append('action', 'mark_all_read');
    
    fetch(NOTIFICATION_API, {
        method: 'POST',
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadNotifications();
                updateNotificationCount();
            }
        })
        .catch(error => {
            console.error('Error marking all notifications as read:', error);
        });
}

// Escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Close modal when clicking outside
window.addEventListener('click', function(event) {
    const modal = document.getElementById('notificationModal');
    if (event.target === modal) {
        closeNotificationModal();
    }
});

