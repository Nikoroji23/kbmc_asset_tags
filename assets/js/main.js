/**
 * KBMC Asset Management - Global UI behaviors
 * This file is the primary JavaScript bundle for sidebar and general UI interactions.
 */

document.addEventListener('DOMContentLoaded', function () {
    var menuToggle = document.getElementById('menuToggle');
    var sidebarClose = document.getElementById('sidebarClose');
    var sidebar = document.getElementById('sidebar');

    function toggleSidebar() {
        if (!sidebar) return;
        sidebar.classList.toggle('collapsed');
    }

    if (menuToggle) {
        menuToggle.addEventListener('click', function () {
            toggleSidebar();
        });
    }

    if (sidebarClose) {
        sidebarClose.addEventListener('click', function () {
            if (!sidebar) return;
            sidebar.classList.add('collapsed');
        });
    }

    function applyResponsiveSidebar() {
        if (!sidebar) return;
        if (window.innerWidth <= 900) {
            sidebar.classList.add('collapsed');
        }
    }

    applyResponsiveSidebar();
    window.addEventListener('resize', applyResponsiveSidebar);

    document.querySelectorAll('.delete-confirm').forEach(function (button) {
        button.addEventListener('click', function (event) {
            if (!confirm('Are you sure you want to delete this item?')) {
                event.preventDefault();
            }
        });
    });

    // Simple modal system: open by [data-modal] and close by [data-dismiss="modal"] or clicking overlay
    function openModalById(id) {
        if (!id) return;
        var modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.add('show');
        modal.style.display = 'flex';
    }

    function closeModalElement(el) {
        if (!el) return;
        var modal = el.closest('.modal-overlay');
        if (!modal) return;
        modal.classList.remove('show');
        modal.style.display = 'none';
    }

    // Attach openers
    document.querySelectorAll('[data-modal]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            var target = btn.getAttribute('data-modal');
            openModalById(target);
        });
    });

    // Attach dismiss buttons
    document.querySelectorAll('[data-dismiss="modal"]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            closeModalElement(btn);
        });
    });

    // Close when clicking overlay background
    document.querySelectorAll('.modal-overlay').forEach(function (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                modal.classList.remove('show');
                modal.style.display = 'none';
            }
        });
    });

    // Expose helper for inline calls from templates
    window.closeViewUserModal = function () { closeModalElement(document.getElementById('viewUserModal') || null); };

    window.exportToCSV = function(filename, headers, rows) {
        var csvRows = [];
        if (headers && headers.length) {
            csvRows.push(headers.map(function(h) { return '"' + String(h).replace(/"/g, '""') + '"'; }).join(','));
        }
        rows.forEach(function(row) {
            csvRows.push(row.map(function(cell) {
                var value = cell === null || cell === undefined ? '' : String(cell);
                return '"' + value.replace(/"/g, '""') + '"';
            }).join(','));
        });
        var blob = new Blob([csvRows.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    };

    window.exportToPDF = function(title, headers, rows, filename) {
        if (typeof window.jspdf === 'undefined' || typeof window.jspdf.jsPDF === 'undefined') {
            alert('PDF export is not available in this browser.');
            return;
        }
        var doc = new window.jspdf.jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
        doc.setFontSize(14);
        doc.text(title, 40, 40);
        doc.autoTable({
            startY: 60,
            head: [headers],
            body: rows,
            theme: 'striped',
            headStyles: { fillColor: [59, 130, 246], textColor: 255 },
            styles: { fontSize: 10, cellPadding: 6, overflow: 'linebreak' },
            columnStyles: { 0: { cellWidth: 'auto' } }
        });
        doc.save(filename);
    };

    // Setup notification dropdown handlers
    (function setupNotifications() {
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
                    if (typeof handleNotificationClick === 'function') {
                        handleNotificationClick(notifItem);
                    }
                }
            });
        }
    })();
});
