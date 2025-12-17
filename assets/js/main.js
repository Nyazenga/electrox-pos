$(document).ready(function() {
    // Initialize DataTables for .data-table elements, but skip those with specific IDs that are initialized manually
    $('.data-table').each(function() {
        var $table = $(this);
        var tableId = $table.attr('id');
        
        // Skip tables that are initialized manually (like refundsTable)
        if (tableId && (tableId === 'refundsTable' || window[tableId + 'Initialized'])) {
            return;
        }
        
        // Skip if already initialized
        if ($.fn.DataTable.isDataTable($table)) {
            return;
        }
        
        $table.DataTable({
            responsive: true,
            pageLength: 25,
            order: [[0, 'desc']],
            language: {
                search: "Search:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries",
                paginate: {
                    first: "First",
                    last: "Last",
                    next: "Next",
                    previous: "Previous"
                }
            }
        });
    });
    
    $('.delete-btn').on('click', function(e) {
        e.preventDefault();
        const url = $(this).attr('href');
        Swal.fire({
            title: 'Are you sure?',
            text: "You won't be able to revert this!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#1e3a8a',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = url;
            }
        });
    });
    
    $('.confirm-btn').on('click', function(e) {
        e.preventDefault();
        const url = $(this).attr('href');
        Swal.fire({
            title: 'Confirm Action',
            text: "Are you sure you want to proceed?",
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#1e3a8a',
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'Yes, proceed!'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = url;
            }
        });
    });
    
    if ($('.alert').length) {
        setTimeout(function() {
            $('.alert').fadeOut();
        }, 5000);
    }
});

function showSuccess(message) {
    Swal.fire({
        icon: 'success',
        title: 'Success!',
        text: message,
        confirmButtonColor: '#1e3a8a'
    });
}

function showError(message) {
    Swal.fire({
        icon: 'error',
        title: 'Error!',
        text: message,
        confirmButtonColor: '#d33'
    });
}

function showConfirm(message, callback) {
    Swal.fire({
        title: 'Are you sure?',
        text: message,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#1e3a8a',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, proceed!'
    }).then((result) => {
        if (result.isConfirmed && callback) {
            callback();
        }
    });
}

