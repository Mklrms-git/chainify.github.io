    <?php if (isLoggedIn()): ?>
        </main>
    </div>
    
    <!-- Notification Modal -->
    <?php if (isset($_SESSION['role']) && ($_SESSION['role'] === 'logistics_manager' || $_SESSION['role'] === 'supplier' || $_SESSION['role'] === 'procurement_staff' || $_SESSION['role'] === 'admin')): ?>
    <div id="notificationModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h3>Notifications</h3>
                <button class="modal-close" onclick="closeNotificationModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="notificationList" style="max-height: 500px; overflow-y: auto;">
                    <div style="text-align: center; padding: 40px; color: #999;">
                        <i class="fas fa-spinner fa-spin" style="font-size: 24px;"></i>
                        <p>Loading notifications...</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="text-align: right; padding: 15px; border-top: 1px solid #eee;">
                <button class="btn btn-secondary" onclick="markAllAsRead()" id="markAllReadBtn" style="display: none;">Mark All as Read</button>
                <button class="btn btn-secondary" onclick="closeNotificationModal()">Close</button>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php endif; ?>
    <script>
        // Set BASE_URL for JavaScript
        window.BASE_URL = '<?php echo BASE_URL; ?>';
    </script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/main.js"></script>
    <?php if (isset($_SESSION['role']) && ($_SESSION['role'] === 'logistics_manager' || $_SESSION['role'] === 'supplier' || $_SESSION['role'] === 'procurement_staff' || $_SESSION['role'] === 'admin')): ?>
    <script src="<?php echo BASE_URL; ?>assets/js/notifications.js"></script>
    <?php endif; ?>
    <?php if (isset($additionalScripts)): ?>
        <?php foreach ($additionalScripts as $script): ?>
            <script src="<?php echo BASE_URL . $script; ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>

