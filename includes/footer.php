            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="<?= ASSETS_URL ?>js/main.js"></script>
    <script>
        
        // Branch selector functionality
        document.addEventListener('DOMContentLoaded', function() {
            const branchOptions = document.querySelectorAll('.branch-option');
            
            branchOptions.forEach(option => {
                option.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    const branchId = this.getAttribute('data-branch-id');
                    const branchName = this.getAttribute('data-branch-name');
                    
                    // Show loading state
                    const currentBranchNameEl = document.getElementById('currentBranchName');
                    const originalText = currentBranchNameEl.textContent;
                    currentBranchNameEl.textContent = 'Changing...';
                    
                    // Disable all options
                    branchOptions.forEach(opt => opt.style.pointerEvents = 'none');
                    
                    // Make AJAX request
                    fetch('<?= BASE_URL ?>ajax/change_branch.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'branch_id=' + encodeURIComponent(branchId)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Update UI
                            currentBranchNameEl.textContent = branchName;
                            
                            // Update active state
                            branchOptions.forEach(opt => {
                                const checkIcon = opt.querySelector('.bi-check-circle-fill');
                                if (opt.getAttribute('data-branch-id') == branchId) {
                                    opt.classList.add('active');
                                    if (checkIcon) checkIcon.classList.remove('d-none');
                                } else {
                                    opt.classList.remove('active');
                                    if (checkIcon) checkIcon.classList.add('d-none');
                                }
                            });
                            
                            // Show success message
                            if (typeof Swal !== 'undefined') {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Branch Changed',
                                    text: 'Branch changed to ' + branchName + '. Page will reload...',
                                    timer: 2000,
                                    showConfirmButton: false
                                }).then(() => {
                                    // Reload page to reflect branch change
                                    window.location.reload();
                                });
                            } else {
                                // Fallback: reload immediately
                                setTimeout(() => window.location.reload(), 500);
                            }
                        } else {
                            // Show error
                            currentBranchNameEl.textContent = originalText;
                            if (typeof Swal !== 'undefined') {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error',
                                    text: data.message || 'Failed to change branch'
                                });
                            } else {
                                alert('Error: ' + (data.message || 'Failed to change branch'));
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        currentBranchNameEl.textContent = originalText;
                        if (typeof Swal !== 'undefined') {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: 'An error occurred while changing branch'
                            });
                        } else {
                            alert('An error occurred while changing branch');
                        }
                    })
                    .finally(() => {
                        // Re-enable options
                        branchOptions.forEach(opt => opt.style.pointerEvents = 'auto');
                    });
                });
            });
        });
        
        // Sidebar toggle
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarBackdrop = document.getElementById('sidebarBackdrop');
        
        if (sidebarToggle && sidebar) {
            sidebarToggle.addEventListener('click', function() {
                // On mobile, toggle show class for drawer behavior
                if (window.innerWidth <= 480) {
                    sidebar.classList.toggle('show');
                    if (sidebarBackdrop) {
                        sidebarBackdrop.classList.toggle('show');
                    }
                } else {
                    // On desktop, toggle collapsed class
                    sidebar.classList.toggle('collapsed');
                }
            });
        }
        
        // Close sidebar on backdrop click (mobile only)
        if (sidebarBackdrop) {
            sidebarBackdrop.addEventListener('click', function() {
                if (window.innerWidth <= 480) {
                    sidebar.classList.remove('show');
                    sidebarBackdrop.classList.remove('show');
                }
            });
        }
        
        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 480) {
                sidebar.classList.remove('show');
                if (sidebarBackdrop) {
                    sidebarBackdrop.classList.remove('show');
                }
            }
        });
        
        // Submenu toggle
        document.querySelectorAll('.sidebar-menu li.has-submenu > a').forEach(function(item) {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                const parent = this.parentElement;
                const isActive = parent.classList.contains('active');
                
                // Close all other submenus
                document.querySelectorAll('.sidebar-menu li.has-submenu').forEach(function(li) {
                    if (li !== parent) {
                        li.classList.remove('active');
                    }
                });
                
                // Toggle current submenu - always toggle regardless of active child
                if (isActive) {
                    parent.classList.remove('active');
                } else {
                    parent.classList.add('active');
                }
            });
        });
        
        // Auto-expand submenus if any child is active (run on page load)
        function autoExpandActiveSubmenus() {
            document.querySelectorAll('.sidebar-menu li.has-submenu').forEach(function(li) {
                const submenu = li.querySelector('.submenu');
                if (submenu) {
                    // Check if any child link is active
                    const activeChild = submenu.querySelector('a.active');
                    if (activeChild) {
                        li.classList.add('active');
                    }
                }
            });
        }
        
        // Run immediately and after DOM is fully loaded
        autoExpandActiveSubmenus();
        document.addEventListener('DOMContentLoaded', autoExpandActiveSubmenus);
    </script>
</body>
</html>

