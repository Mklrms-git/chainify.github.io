<?php
require_once '../config/config.php';
requireRole(['admin']);

$conn = getDBConnection();

// Get messages from session (set after redirect)
$message = '';
$error = '';

if (isset($_SESSION['users_message'])) {
    $message = $_SESSION['users_message'];
    unset($_SESSION['users_message']);
}

if (isset($_SESSION['users_error'])) {
    $error = $_SESSION['users_error'];
    unset($_SESSION['users_error']);
}

// Also check for GET parameters (for compatibility)
if (isset($_GET['success']) && empty($message)) {
    $message = 'Operation completed successfully!';
}
if (isset($_GET['error']) && empty($error)) {
    $error = 'An error occurred. Please try again.';
}

// Add/Update User
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_user'])) {
    $user_id = intval($_POST['user_id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? '';
    $full_name = trim($_POST['full_name'] ?? '');
    $status = $_POST['status'] ?? 'active';
    
    // Validate required fields
    if (empty($username) || empty($email) || empty($role) || empty($full_name)) {
        $_SESSION['users_error'] = 'Please fill all required fields.';
        closeDBConnection($conn);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
        exit();
    } elseif ($user_id == 0 && empty($password)) {
        $_SESSION['users_error'] = 'Password is required for new users.';
        closeDBConnection($conn);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
        exit();
    }
    
    // Check for duplicate username/email (only for new users or if changed)
    if ($user_id == 0) {
        $check = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $check->bind_param("ss", $username, $email);
        $check->execute();
        $result = $check->get_result();
        if ($result->num_rows > 0) {
            $_SESSION['users_error'] = 'Username or email already exists.';
            $check->close();
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit();
        }
        $check->close();
    } else {
        // For updates, check if username/email exists for other users
        $check = $conn->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
        $check->bind_param("ssi", $username, $email, $user_id);
        $check->execute();
        $result = $check->get_result();
        if ($result->num_rows > 0) {
            $_SESSION['users_error'] = 'Username or email already exists.';
            $check->close();
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit();
        }
        $check->close();
    }
    
    // Proceed with insert/update
    if ($user_id > 0) {
        // Update existing user
        if (!empty($password)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET username=?, email=?, password=?, role=?, full_name=?, status=? WHERE id=?");
            $stmt->bind_param("ssssssi", $username, $email, $hashed_password, $role, $full_name, $status, $user_id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET username=?, email=?, role=?, full_name=?, status=? WHERE id=?");
            $stmt->bind_param("sssssi", $username, $email, $role, $full_name, $status, $user_id);
        }
    } else {
        // Insert new user
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (username, email, password, role, full_name, status) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssss", $username, $email, $hashed_password, $role, $full_name, $status);
    }
    
    if ($stmt->execute()) {
        $new_user_id = $user_id > 0 ? $user_id : $stmt->insert_id;
        
        // If role is supplier, link to supplier record if supplier_id is provided
        if ($role === 'supplier') {
            $supplier_id = intval($_POST['supplier_id'] ?? 0);
            if ($supplier_id > 0) {
                // Check if supplier exists and is not already linked
                $supplier_check = $conn->prepare("SELECT id, user_id FROM suppliers WHERE id = ?");
                $supplier_check->bind_param("i", $supplier_id);
                $supplier_check->execute();
                $supplier_result = $supplier_check->get_result();
                $supplier_data = $supplier_result->fetch_assoc();
                $supplier_check->close();
                
                if ($supplier_data) {
                    // Unlink previous user if exists
                    if ($supplier_data['user_id'] && $supplier_data['user_id'] != $new_user_id) {
                        $unlink_stmt = $conn->prepare("UPDATE suppliers SET user_id = NULL WHERE id = ?");
                        $unlink_stmt->bind_param("i", $supplier_id);
                        $unlink_stmt->execute();
                        $unlink_stmt->close();
                    }
                    
                    // Link new user
                    $link_stmt = $conn->prepare("UPDATE suppliers SET user_id = ? WHERE id = ?");
                    $link_stmt->bind_param("ii", $new_user_id, $supplier_id);
                    $link_stmt->execute();
                    $link_stmt->close();
                }
            }
        } else {
            // If role changed from supplier, unlink from supplier
            if ($user_id > 0) {
                $unlink_stmt = $conn->prepare("UPDATE suppliers SET user_id = NULL WHERE user_id = ?");
                $unlink_stmt->bind_param("i", $user_id);
                $unlink_stmt->execute();
                $unlink_stmt->close();
            }
        }
        
        // Store message in session and redirect to prevent duplicate submissions
        $_SESSION['users_message'] = $user_id > 0 ? 'User updated successfully!' : 'User added successfully!';
        $stmt->close();
        closeDBConnection($conn);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1');
        exit();
    } else {
        $_SESSION['users_error'] = 'Failed to save user: ' . $stmt->error;
        $stmt->close();
        closeDBConnection($conn);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
        exit();
    }
}

// Delete/Deactivate User
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['deactivate_user'])) {
    $user_id = $_POST['user_id'] ?? 0;
    
    if ($user_id && $user_id != $_SESSION['user_id']) {
        // Get user details for confirmation
        $user_check = $conn->prepare("SELECT username, full_name, status FROM users WHERE id = ?");
        $user_check->bind_param("i", $user_id);
        $user_check->execute();
        $user_result = $user_check->get_result();
        $user_data = $user_result->fetch_assoc();
        $user_check->close();
        
        if (!$user_data) {
            $_SESSION['users_error'] = 'User not found.';
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit();
        }
        
        if ($user_data['status'] == 'inactive') {
            $_SESSION['users_error'] = 'User is already inactive.';
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit();
        }
        
        $stmt = $conn->prepare("UPDATE users SET status='inactive' WHERE id=?");
        $stmt->bind_param("i", $user_id);
        
        if ($stmt->execute()) {
            $_SESSION['users_message'] = "User '{$user_data['username']}' deactivated successfully!";
            $stmt->close();
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1');
            exit();
        } else {
            $_SESSION['users_error'] = 'Failed to deactivate user: ' . $stmt->error;
            $stmt->close();
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit();
        }
    } else {
        $_SESSION['users_error'] = 'You cannot deactivate your own account.';
        closeDBConnection($conn);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
        exit();
    }
}

// Activate User
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['activate_user'])) {
    $user_id = $_POST['user_id'] ?? 0;
    
    if ($user_id) {
        // Get user details for confirmation
        $user_check = $conn->prepare("SELECT username, full_name, status FROM users WHERE id = ?");
        $user_check->bind_param("i", $user_id);
        $user_check->execute();
        $user_result = $user_check->get_result();
        $user_data = $user_result->fetch_assoc();
        $user_check->close();
        
        if (!$user_data) {
            $_SESSION['users_error'] = 'User not found.';
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit();
        }
        
        if ($user_data['status'] == 'active') {
            $_SESSION['users_error'] = 'User is already active.';
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit();
        }
        
        $stmt = $conn->prepare("UPDATE users SET status='active' WHERE id=?");
        $stmt->bind_param("i", $user_id);
        
        if ($stmt->execute()) {
            $_SESSION['users_message'] = "User '{$user_data['username']}' activated successfully!";
            $stmt->close();
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1');
            exit();
        } else {
            $_SESSION['users_error'] = 'Failed to activate user: ' . $stmt->error;
            $stmt->close();
            closeDBConnection($conn);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
            exit();
        }
    } else {
        $_SESSION['users_error'] = 'Invalid user ID for activation.';
        closeDBConnection($conn);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
        exit();
    }
}

// Handle quick link supplier account
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['link_supplier'])) {
    $user_id = intval($_POST['user_id'] ?? 0);
    $supplier_id = intval($_POST['supplier_id'] ?? 0);
    
    if ($user_id && $supplier_id) {
        // Verify user is supplier role
        $user_check = $conn->prepare("SELECT role FROM users WHERE id = ?");
        $user_check->bind_param("i", $user_id);
        $user_check->execute();
        $user_result = $user_check->get_result();
        $user_data = $user_result->fetch_assoc();
        $user_check->close();
        
        if ($user_data && $user_data['role'] === 'supplier') {
            // Unlink previous supplier if exists
            $unlink_stmt = $conn->prepare("UPDATE suppliers SET user_id = NULL WHERE user_id = ?");
            $unlink_stmt->bind_param("i", $user_id);
            $unlink_stmt->execute();
            $unlink_stmt->close();
            
            // Link to new supplier
            $link_stmt = $conn->prepare("UPDATE suppliers SET user_id = ? WHERE id = ?");
            $link_stmt->bind_param("ii", $user_id, $supplier_id);
            
            if ($link_stmt->execute()) {
                $_SESSION['users_message'] = 'Supplier account linked successfully!';
            } else {
                $_SESSION['users_error'] = 'Failed to link supplier account: ' . $link_stmt->error;
            }
            $link_stmt->close();
        } else {
            $_SESSION['users_error'] = 'User must have supplier role to link to supplier.';
        }
        
        closeDBConnection($conn);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1');
        exit();
    }
}

// Get user for editing
$edit_user = null;
if (isset($_GET['edit'])) {
    $user_id = $_GET['edit'];
    $stmt = $conn->prepare("
        SELECT u.*, s.id as linked_supplier_id 
        FROM users u 
        LEFT JOIN suppliers s ON u.id = s.user_id 
        WHERE u.id = ?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $edit_user = $result->fetch_assoc();
    $stmt->close();
}

// Get users with supplier link info
$users = [];
$result = $conn->query("
    SELECT u.*, s.id as linked_supplier_id, s.company_name as linked_supplier_name
    FROM users u
    LEFT JOIN suppliers s ON u.id = s.user_id
    ORDER BY u.created_at DESC
");
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}

// Get suppliers for linking (only active suppliers without user accounts, or linked to current user if editing)
$suppliers = [];
$supplier_query = "
    SELECT id, company_name, email, user_id
    FROM suppliers 
    WHERE status = 'active'
    ORDER BY company_name
";
$result = $conn->query($supplier_query);
while ($row = $result->fetch_assoc()) {
    $suppliers[] = $row;
}

closeDBConnection($conn);

$pageTitle = 'Users Management';
include '../includes/header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-users"></i> Users Management</h2>
    <button class="btn btn-primary" onclick="openModal('userModal')">
        <i class="fas fa-plus"></i> Add User
    </button>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="dashboard-card">
    <div class="card-header">
        <h3><i class="fas fa-list"></i> User List</h3>
    </div>
    <div class="card-body">
        <?php if (empty($users)): ?>
            <p class="text-muted">No users found.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Full Name</th>
                        <th>Role</th>
                        <th>Linked Supplier</th>
                        <th>Status</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($user['username']); ?></strong></td>
                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                            <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                            <td>
                                <span class="badge badge-<?php echo $user['role'] == 'admin' ? 'danger' : 'info'; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($user['role'] === 'supplier'): ?>
                                    <?php if ($user['linked_supplier_name']): ?>
                                        <span class="badge badge-success" title="Linked to supplier">
                                            <i class="fas fa-link"></i> <?php echo htmlspecialchars($user['linked_supplier_name']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-warning" title="Not linked to any supplier">
                                            <i class="fas fa-unlink"></i> Not Linked
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo $user['status']; ?>">
                                    <?php echo ucfirst($user['status']); ?>
                                </span>
                            </td>
                            <td><?php echo formatDate($user['created_at']); ?></td>
                            <td>
                                <a href="?edit=<?php echo $user['id']; ?>" class="btn btn-sm btn-secondary" onclick="openUserModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars(addslashes($user['username'])); ?>', '<?php echo htmlspecialchars(addslashes($user['email'])); ?>', '<?php echo htmlspecialchars(addslashes($user['full_name'])); ?>', '<?php echo $user['role']; ?>', '<?php echo $user['status']; ?>', <?php echo $user['linked_supplier_id'] ?? 'null'; ?>); return false;">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <?php if ($user['role'] === 'supplier' && !$user['linked_supplier_id']): ?>
                                    <button class="btn btn-sm btn-info" onclick="openLinkSupplierModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username'], ENT_QUOTES); ?>')" title="Link to Supplier">
                                        <i class="fas fa-link"></i> Link Supplier
                                    </button>
                                <?php endif; ?>
                                <?php if ($user['id'] != $_SESSION['user_id'] && $user['status'] == 'active'): ?>
                                    <button class="btn btn-sm btn-danger" onclick="confirmDeactivateUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($user['full_name'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-ban"></i> Deactivate
                                    </button>
                                <?php elseif ($user['status'] == 'inactive'): ?>
                                    <button class="btn btn-sm btn-success" onclick="confirmActivateUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($user['full_name'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-check"></i> Activate
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- User Modal -->
<div id="userModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Add User</h3>
            <button class="modal-close" onclick="closeModal('userModal')">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="user_id" id="user_id" value="0">
            
            <div class="form-group">
                <label for="username">Username *</label>
                <input type="text" name="username" id="username" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label for="email">Email *</label>
                <input type="email" name="email" id="email" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label for="full_name">Full Name *</label>
                <input type="text" name="full_name" id="full_name" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label for="role">Role *</label>
                <select name="role" id="role" class="form-control" required onchange="toggleSupplierField()">
                    <option value="">Select Role</option>
                    <option value="admin">Admin</option>
                    <option value="procurement_staff">Procurement Staff</option>
                    <option value="warehouse_officer">Warehouse Officer</option>
                    <option value="logistics_manager">Logistics Manager</option>
                    <option value="supplier">Supplier</option>
                </select>
            </div>
            
            <div class="form-group" id="supplierLinkGroup" style="display: none;">
                <label for="supplier_id">Link to Supplier *</label>
                <select name="supplier_id" id="supplier_id" class="form-control">
                    <option value="">Select Supplier</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <?php 
                        // Show supplier if not linked or linked to current user being edited
                        $can_select = !$supplier['user_id'] || 
                                      (isset($edit_user) && $edit_user['id'] && $supplier['user_id'] == $edit_user['id']);
                        ?>
                        <?php if ($can_select): ?>
                            <option value="<?php echo $supplier['id']; ?>" 
                                    <?php echo (isset($edit_user) && $edit_user['linked_supplier_id'] == $supplier['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($supplier['company_name']); ?>
                                <?php if ($supplier['email']): ?>
                                    (<?php echo htmlspecialchars($supplier['email']); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Select the supplier company this user account should be linked to</small>
            </div>
            
            <div class="form-group">
                <label for="password" id="passwordLabel">Password *</label>
                <input type="password" name="password" id="password" class="form-control" required>
                <small class="text-muted" id="passwordHint" style="display: none;">Leave blank to keep current password (for editing)</small>
            </div>
            
            <div class="form-group">
                <label for="status">Status *</label>
                <select name="status" id="status" class="form-control" required>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            
            <div class="form-group">
                <button type="submit" name="save_user" class="btn btn-primary btn-block">
                    <i class="fas fa-save"></i> Save User
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openUserModal(id, username, email, fullName, role, status, linkedSupplierId) {
    const form = document.getElementById('userModal').querySelector('form');
    const passwordField = document.getElementById('password');
    const passwordLabel = document.getElementById('passwordLabel');
    const passwordHint = document.getElementById('passwordHint');
    const modalTitle = document.getElementById('modalTitle');
    
    document.getElementById('user_id').value = id;
    document.getElementById('username').value = username || '';
    document.getElementById('email').value = email || '';
    document.getElementById('full_name').value = fullName || '';
    document.getElementById('role').value = role || '';
    document.getElementById('status').value = status || 'active';
    document.getElementById('supplier_id').value = linkedSupplierId || '';
    passwordField.value = '';
    
    // Toggle supplier field based on role
    toggleSupplierField();
    
    if (id == 0) {
        // New user
        passwordField.required = true;
        passwordField.removeAttribute('readonly');
        passwordLabel.textContent = 'Password *';
        passwordHint.style.display = 'none';
        modalTitle.textContent = 'Add User';
    } else {
        // Edit user
        passwordField.required = false;
        passwordLabel.textContent = 'Password (leave blank to keep current)';
        passwordHint.style.display = 'block';
        modalTitle.textContent = 'Edit User';
    }
    
    openModal('userModal');
}

function toggleSupplierField() {
    const roleSelect = document.getElementById('role');
    const supplierGroup = document.getElementById('supplierLinkGroup');
    const supplierSelect = document.getElementById('supplier_id');
    
    if (roleSelect.value === 'supplier') {
        supplierGroup.style.display = 'block';
        supplierSelect.required = true;
    } else {
        supplierGroup.style.display = 'none';
        supplierSelect.required = false;
        supplierSelect.value = '';
    }
}

function openLinkSupplierModal(userId, username) {
    document.getElementById('linkSupplierUserId').value = userId;
    document.getElementById('linkSupplierUsername').textContent = username;
    openModal('linkSupplierModal');
}

// Reset form when opening modal for new user
document.addEventListener('DOMContentLoaded', function() {
    const addUserBtn = document.querySelector('.page-header .btn-primary');
    const userModal = document.getElementById('userModal');
    
    if (addUserBtn) {
        addUserBtn.addEventListener('click', function() {
            const form = userModal.querySelector('form');
            const passwordField = document.getElementById('password');
            
            form.reset();
            document.getElementById('user_id').value = '0';
            passwordField.required = true;
            passwordField.removeAttribute('readonly');
            document.getElementById('passwordLabel').textContent = 'Password *';
            document.getElementById('passwordHint').style.display = 'none';
            document.getElementById('modalTitle').textContent = 'Add User';
        });
    }
    
    // Reset form when closing modal
    userModal.addEventListener('click', function(e) {
        if (e.target.classList.contains('modal') || e.target.classList.contains('modal-close')) {
            const form = userModal.querySelector('form');
            form.reset();
            document.getElementById('user_id').value = '0';
            document.getElementById('password').required = true;
            document.getElementById('passwordLabel').textContent = 'Password *';
            document.getElementById('passwordHint').style.display = 'none';
            document.getElementById('modalTitle').textContent = 'Add User';
        }
    });
});

function confirmDeactivateUser(userId, username, fullName) {
    // Set the user details in the modal
    document.getElementById('deactivateUserId').value = userId;
    document.getElementById('deactivateUsername').textContent = username;
    document.getElementById('deactivateFullName').textContent = fullName;
    
    // Show the confirmation modal
    openModal('deactivateUserModal');
}

function confirmActivateUser(userId, username, fullName) {
    // Set the user details in the modal
    document.getElementById('activateUserId').value = userId;
    document.getElementById('activateUsername').textContent = username;
    document.getElementById('activateFullName').textContent = fullName;
    
    // Show the confirmation modal
    openModal('activateUserModal');
}
</script>

<!-- Deactivate User Confirmation Modal -->
<div id="deactivateUserModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3><i class="fas fa-exclamation-triangle" style="color: #dc3545;"></i> Confirm Deactivation</h3>
            <button class="modal-close" onclick="closeModal('deactivateUserModal')">&times;</button>
        </div>
        <div class="card-body">
            <p style="margin-bottom: 20px; font-size: 16px;">Are you sure you want to deactivate this user?</p>
            <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px;">
                <div style="margin-bottom: 10px;">
                    <strong>Username:</strong> <span id="deactivateUsername"></span>
                </div>
                <div>
                    <strong>Full Name:</strong> <span id="deactivateFullName"></span>
                </div>
            </div>
            <div style="padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px; margin-bottom: 20px;">
                <i class="fas fa-info-circle"></i> This will mark the user as inactive. They will not be able to log in to the system. You can reactivate them later if needed.
            </div>
            <form method="POST" action="" id="deactivateUserForm">
                <input type="hidden" name="user_id" id="deactivateUserId">
                <input type="hidden" name="deactivate_user" value="1">
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('deactivateUserModal')">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-ban"></i> Deactivate User
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Activate User Confirmation Modal -->
<div id="activateUserModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3><i class="fas fa-check-circle" style="color: #28a745;"></i> Confirm Activation</h3>
            <button class="modal-close" onclick="closeModal('activateUserModal')">&times;</button>
        </div>
        <div class="card-body">
            <p style="margin-bottom: 20px; font-size: 16px;">Are you sure you want to activate this user?</p>
            <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px;">
                <div style="margin-bottom: 10px;">
                    <strong>Username:</strong> <span id="activateUsername"></span>
                </div>
                <div>
                    <strong>Full Name:</strong> <span id="activateFullName"></span>
                </div>
            </div>
            <div style="padding: 10px; background: #d4edda; border-left: 4px solid #28a745; border-radius: 4px; margin-bottom: 20px;">
                <i class="fas fa-info-circle"></i> This will restore the user's access to the system. They will be able to log in and perform actions according to their role permissions.
            </div>
            <form method="POST" action="" id="activateUserForm">
                <input type="hidden" name="user_id" id="activateUserId">
                <input type="hidden" name="activate_user" value="1">
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('activateUserModal')">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check"></i> Activate User
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Link Supplier Modal -->
<div id="linkSupplierModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Link Supplier Account</h3>
            <button class="modal-close" onclick="closeModal('linkSupplierModal')">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="user_id" id="linkSupplierUserId">
            <input type="hidden" name="link_supplier" value="1">
            
            <div class="form-group">
                <label>User</label>
                <input type="text" class="form-control" id="linkSupplierUsername" readonly>
            </div>
            
            <div class="form-group">
                <label for="linkSupplierId">Select Supplier *</label>
                <select name="supplier_id" id="linkSupplierId" class="form-control" required>
                    <option value="">Select Supplier</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <?php if (!$supplier['user_id']): ?>
                            <option value="<?php echo $supplier['id']; ?>">
                                <?php echo htmlspecialchars($supplier['company_name']); ?>
                                <?php if ($supplier['email']): ?>
                                    (<?php echo htmlspecialchars($supplier['email']); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Select the supplier company to link this user account to</small>
            </div>
            
            <div class="form-group">
                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-link"></i> Link Supplier
                </button>
            </div>
        </form>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

