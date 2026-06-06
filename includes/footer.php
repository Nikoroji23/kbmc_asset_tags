        </main>

        <!-- Footer -->
        <footer class="app-footer">
            <div class="footer-left">
                <span class="footer-logo">KBMC</span> Device Arrival & Asset Management System
            </div>
            <div class="footer-right">
                &copy; <?php echo date('Y'); ?> Kitchen Beauty Marketing Corporation. All rights reserved.
            </div>
        </footer>
    </div>

    <!-- Global modal for assigned-user details (used when clicking legacy it_user_details.php links) -->
    <div id="globalAssignedUserModal" class="modal-overlay" style="display:none;">
        <div class="modal-box" style="max-width:960px;width:95%;">
            <div class="modal-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px;">
                <h3><i class="fas fa-id-card"></i> Employee Details</h3>
                <div style="display:flex;gap:8px;align-items:center;">
                    <button type="button" class="btn btn-primary" id="globalAssignedUserPDF" style="display:flex;align-items:center;gap:6px;">
                        <i class="fas fa-file-pdf"></i> PDF
                    </button>
                    <button class="modal-close btn btn-outline" id="globalAssignedUserClose">&times;</button>
                </div>
            </div>
            <div class="modal-body" id="globalAssignedUserBody" style="padding:20px;">
                <p style="text-align:center;color:#999;padding:30px;"><i class="fas fa-spinner fa-spin"></i> Loading...</p>
            </div>
        </div>
    </div>

    <script src="assets/js/main.js"></script>
    <script src="assets/js/it_user_modal.js"></script>
    <script>
        // Setup notification handlers with guaranteed timing
        function setupNotificationHandlers() {
            var notifToggle = document.getElementById('notifToggle');
            var notifDropdown = document.getElementById('notifDropdown');

            if (notifToggle && notifDropdown) {
                // Toggle dropdown on bell button click
                notifToggle.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    notifDropdown.classList.toggle('show');
                });

                // Close dropdown when clicking outside
                document.addEventListener('click', function (event) {
                    if (!notifDropdown.contains(event.target) && event.target !== notifToggle && !notifToggle.contains(event.target)) {
                        notifDropdown.classList.remove('show');
                    }
                });

                // Handle clicks on notification items in the dropdown
                notifDropdown.addEventListener('click', function(event) {
                    var notifItem = event.target.closest('.notif-item');
                    if (notifItem) {
                        event.stopPropagation();
                        handleNotificationClick(notifItem);
                    }
                });
            }

            // Also handle static notification list items (.notif-clickable)
            var notifClickables = document.querySelectorAll('.notif-clickable');
            notifClickables.forEach(function(item) {
                item.addEventListener('click', function(event) {
                    if (event.target.closest('a')) return;
                    handleNotificationClick(this);
                });
            });
        }

        // Run immediately if DOM is ready, otherwise wait
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', setupNotificationHandlers);
        } else {
            setupNotificationHandlers();
        }
    </script>
<?php ob_end_flush(); ?>
</body>
</html>
