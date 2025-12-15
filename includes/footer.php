            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="<?= ASSETS_URL ?>js/main.js"></script>
    <script>
        // Analog Clock
        function drawClock() {
            const canvas = document.getElementById('analogClock');
            if (!canvas) return;
            
            const ctx = canvas.getContext('2d');
            const radius = canvas.height / 2 - 10;
            ctx.translate(radius + 10, radius + 10);
            
            function drawFace(ctx, radius) {
                ctx.beginPath();
                ctx.arc(0, 0, radius, 0, 2 * Math.PI);
                ctx.fillStyle = 'rgba(255,255,255,0.1)';
                ctx.fill();
                ctx.strokeStyle = 'rgba(255,255,255,0.3)';
                ctx.lineWidth = 2;
                ctx.stroke();
                
                ctx.beginPath();
                ctx.arc(0, 0, radius * 0.1, 0, 2 * Math.PI);
                ctx.fillStyle = 'rgba(255,255,255,0.9)';
                ctx.fill();
            }
            
            function drawNumbers(ctx, radius) {
                let ang;
                let num;
                ctx.font = radius * 0.15 + "px Poppins";
                ctx.textBaseline = "middle";
                ctx.textAlign = "center";
                ctx.fillStyle = 'rgba(255,255,255,0.9)';
                for(num = 1; num < 13; num++){
                    ang = num * Math.PI / 6;
                    ctx.rotate(ang);
                    ctx.translate(0, -radius * 0.85);
                    ctx.rotate(-ang);
                    ctx.fillText(num.toString(), 0, 0);
                    ctx.rotate(ang);
                    ctx.translate(0, radius * 0.85);
                    ctx.rotate(-ang);
                }
            }
            
            function drawTime(ctx, radius) {
                const now = new Date();
                let hour = now.getHours();
                let minute = now.getMinutes();
                let second = now.getSeconds();
                
                hour = hour % 12;
                hour = (hour * Math.PI / 6) + (minute * Math.PI / (6 * 60)) + (second * Math.PI / (360 * 60));
                drawHand(ctx, hour, radius * 0.5, radius * 0.07, 'rgba(255,255,255,0.9)');
                
                minute = (minute * Math.PI / 30) + (second * Math.PI / (30 * 60));
                drawHand(ctx, minute, radius * 0.8, radius * 0.07, 'rgba(255,255,255,0.9)');
                
                second = (second * Math.PI / 30);
                drawHand(ctx, second, radius * 0.9, radius * 0.02, 'rgba(255,255,255,0.7)');
            }
            
            function drawHand(ctx, pos, length, width, color) {
                ctx.beginPath();
                ctx.lineWidth = width;
                ctx.lineCap = "round";
                ctx.strokeStyle = color;
                ctx.moveTo(0, 0);
                ctx.rotate(pos);
                ctx.lineTo(0, -length);
                ctx.stroke();
                ctx.rotate(-pos);
            }
            
            function updateClock() {
                ctx.clearRect(-radius - 10, -radius - 10, canvas.width, canvas.height);
                drawFace(ctx, radius);
                drawNumbers(ctx, radius);
                drawTime(ctx, radius);
            }
            
            setInterval(updateClock, 1000);
            updateClock();
        }
        
        // Update sidebar date
        function updateSidebarDate() {
            const now = new Date();
            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            const dateEl = document.getElementById('sidebarDate');
            if (dateEl) {
                dateEl.textContent = now.toLocaleDateString('en-US', options);
            }
        }
        
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
                const submenu = parent.querySelector('.submenu');
                const hasActiveChild = submenu && submenu.querySelector('a.active');
                
                // Close all other submenus (except if they have active children)
                document.querySelectorAll('.sidebar-menu li.has-submenu').forEach(function(li) {
                    if (li !== parent) {
                        const otherSubmenu = li.querySelector('.submenu');
                        const otherHasActiveChild = otherSubmenu && otherSubmenu.querySelector('a.active');
                        // Only close if it doesn't have an active child
                        if (!otherHasActiveChild) {
                            li.classList.remove('active');
                        }
                    }
                });
                
                // Toggle current submenu (but keep it open if it has an active child)
                if (hasActiveChild) {
                    parent.classList.add('active');
                } else if (isActive) {
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
        
        // Initialize
        drawClock();
        updateSidebarDate();
        setInterval(updateSidebarDate, 60000);
    </script>
</body>
</html>

