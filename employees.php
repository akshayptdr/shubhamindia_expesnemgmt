<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Role-based Access Control
$allowed_roles = ['Director', 'Accounts Manager', 'Accounts Assistant'];
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], $allowed_roles)) {
    header("Location: index.php");
    exit;
}
require 'includes/db.php';

// Search & Pagination Logic
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = 5;

$query = "SELECT * FROM employees";
$countQuery = "SELECT COUNT(*) FROM employees";
$params = [];

if ($search !== '') {
    $where = " WHERE name LIKE :search OR email LIKE :search OR role LIKE :search";
    $query .= $where;
    $countQuery .= $where;
    $params[':search'] = "%$search%";
}

// Get total count
$stmtCount = $pdo->prepare($countQuery);
$stmtCount->execute($params);
$totalEmployees = $stmtCount->fetchColumn();

$totalPages = ceil($totalEmployees / $perPage);
if ($page > $totalPages && $totalPages > 0) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;

// Get data for current page
$query .= " ORDER BY id DESC LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($query);

if ($search !== '') {
    $stmt->bindValue(':search', "%$search%", PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();
$pagedEmployees = $stmt->fetchAll();
$current_page = 'employees';
$page_title = 'Employees';
include 'includes/app_header.php';
?>

<!-- Page Content -->
<div class="page-content">
    <div class="page-header">
        <div class="page-title">
            <h2>Employees</h2>
            <p>Directory of all active members and their system permissions.</p>
        </div>
        <button class="btn-primary" id="openModalBtn">
            <i class="ph ph-plus"></i>
            Add New Employee
        </button>
    </div>

    <!-- Data Table -->
    <div class="data-table-container">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Mobile Number</th>
                        <th>Role</th>
                        <th>Limit</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pagedEmployees as $employee): ?>
                        <tr>
                            <td>
                                <div class="employee-cell">
                                    <div class="employee-info">
                                        <h4>
                                            <?php echo htmlspecialchars($employee['name']); ?>
                                        </h4>
                                        <span>Emp ID: #
                                            <?php echo htmlspecialchars($employee['emp_id']); ?>
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td><span class="text-muted">
                                    <?php echo $employee['email']; ?>
                                </span></td>
                            <td>
                                <span class="text-muted" style="display: block; max-width: 100px; line-height: 1.4;">
                                    <?php echo str_replace(' ', '<br>', $employee['mobile']); ?>
                                </span>
                            </td>
                            <td><span class="font-medium text-primary">
                                    <?php echo $employee['role']; ?>
                                </span></td>
                            <td><span class="text-muted">₹
                                    <?php echo number_format($employee['payment_request_limit'] ?? 0, 2); ?>
                                </span></td>
                            <td>
                                <div class="actions-cell">
                                    <button class="edit-btn" data-id="<?php echo $employee['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($employee['name']); ?>"
                                        data-role="<?php echo htmlspecialchars($employee['role']); ?>"
                                        data-email="<?php echo htmlspecialchars($employee['email']); ?>"
                                        data-mobile="<?php echo htmlspecialchars($employee['mobile']); ?>"
                                        data-password="<?php echo htmlspecialchars($employee['password']); ?>"
                                        data-limit="<?php echo htmlspecialchars($employee['payment_request_limit'] ?? 0); ?>">
                                        <i class="ph ph-pencil-simple"></i>
                                    </button>
                                    <label class="switch">
                                        <input type="checkbox" class="status-toggle" data-id="<?php echo $employee['id']; ?>"
                                            <?php echo strtolower($employee['status']) === 'active' ? 'checked' : ''; ?>>
                                        <span class="slider"></span>
                                    </label>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="table-footer">
            <div class="table-info">
                Showing <?php echo $totalEmployees > 0 ? $offset + 1 : 0; ?> to
                <?php echo min($offset + $perPage, $totalEmployees); ?> of <?php echo $totalEmployees; ?>
                employees
            </div>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>" class="page-btn"><i
                            class="ph ph-caret-left"></i></a>
                <?php else: ?>
                    <button class="page-btn" disabled style="opacity: 0.5; cursor: not-allowed;"><i
                            class="ph ph-caret-left"></i></button>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>"
                        class="page-btn <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>" class="page-btn"><i
                            class="ph ph-caret-right"></i></a>
                <?php else: ?>
                    <button class="page-btn" disabled style="opacity: 0.5; cursor: not-allowed;"><i
                            class="ph ph-caret-right"></i></button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</main>
<!-- Add New Employee Modal -->
<div class="modal-overlay" id="employeeModal">
    <div class="modal-content">
        <div class="modal-header">
            <div class="modal-title">
                <h3 id="modal-title">Add New Employee</h3>
                <p id="modal-subtitle">Fill in the details to invite a new team member.</p>
            </div>
            <button class="close-btn" id="closeModalBtn">
                <i class="ph ph-x"></i>
            </button>
        </div>
        <form action="add_employee.php" method="POST" id="employee-form">
            <input type="hidden" name="employee_id" id="employee-id">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-input" placeholder="e.g. John Doe" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Role <span class="text-danger">*</span></label>
                        <div class="select-wrapper">
                            <select name="role" class="form-select" required>
                                <option value="">Select Role</option>
                                <option value="Fitter">Fitter</option>
                                <option value="Senior Fitter">Senior Fitter</option>
                                <option value="Engineer">Engineer</option>
                                <option value="Senior Engineer">Senior Engineer</option>
                                <option value="Project Manager">Project Manager</option>
                                <option value="Senior Project Manager">Senior Project Manager</option>
                                <option value="Director">Director</option>
                                <option value="Accounts Manager">Accounts Manager</option>
                                <option value="Accounts Assistant">Accounts Assistant</option>
                            </select>
                            <i class="ph ph-caret-down"></i>
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Email Address <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-input" placeholder="e.g. john@company.com"
                            required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Mobile Number <span class="text-danger">*</span></label>
                        <input type="text" name="mobile" class="form-input" placeholder="+1 (555) 000-0000" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Payment Request Limit (₹)</label>
                    <input type="number" step="0.01" name="payment_request_limit" class="form-input" placeholder="0.00">
                </div>
                <div class="form-group" id="password-group">
                    <label class="form-label">Password <span class="text-danger">*</span></label>
                    <input type="text" name="password" class="form-input" placeholder="8-digit password">
                    <small style="color: var(--text-secondary); font-size: 11px; margin-top: 4px;">For new employees, an
                        8-digit password is auto-generated if left blank.</small>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="cancelModalBtn">Cancel</button>
                <button type="submit" class="btn-primary" id="submit-btn" style="margin: 0;">Add Employee</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Modal logic
    const modal = document.getElementById('employeeModal');
    const addEmployeeBtn = document.getElementById('openModalBtn');
    const closeModalBtn = document.getElementById('closeModalBtn');
    const cancelModalBtn = document.getElementById('cancelModalBtn');
    const employeeForm = document.getElementById('employee-form');
    const modalTitle = document.getElementById('modal-title');
    const modalSubtitle = document.getElementById('modal-subtitle');
    const submitBtn = document.getElementById('submit-btn');
    const employeeIdInput = document.getElementById('employee-id');
    const passwordGroup = document.getElementById('password-group');

    function openModal(mode = 'add', data = {}) {
        if (mode === 'edit') {
            modalTitle.textContent = 'Edit Employee';
            modalSubtitle.textContent = 'Update the details for this team member.';
            submitBtn.textContent = 'Update Employee';
            employeeForm.action = 'handlers/edit_employee.php';
            passwordGroup.style.display = 'block';

            employeeIdInput.value = data.id;
            document.getElementsByName('full_name')[0].value = data.name;
            document.getElementsByName('role')[0].value = data.role;
            document.getElementsByName('email')[0].value = data.email;
            document.getElementsByName('mobile')[0].value = data.mobile;
            document.getElementsByName('password')[0].value = data.password;
            document.getElementsByName('payment_request_limit')[0].value = data.limit;
        } else {
            modalTitle.textContent = 'Add New Employee';
            modalSubtitle.textContent = 'Fill in the details to invite a new team member.';
            submitBtn.textContent = 'Add Employee';
            employeeForm.action = 'handlers/add_employee.php';
            passwordGroup.style.display = 'none';

            employeeForm.reset();
            employeeIdInput.value = '';
        }
        modal.style.display = 'flex';
    }

    function closeModal() {
        modal.style.display = 'none';
    }

    if (addEmployeeBtn) {
        addEmployeeBtn.addEventListener('click', () => openModal('add'));
    }

    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            openModal('edit', {
                id: this.dataset.id,
                name: this.dataset.name,
                role: this.dataset.role,
                email: this.dataset.email,
                mobile: this.dataset.mobile,
                password: this.dataset.password,
                limit: this.dataset.limit
            });
        });
    });

    if (closeModalBtn) closeModalBtn.addEventListener('click', closeModal);
    if (cancelModalBtn) cancelModalBtn.addEventListener('click', closeModal);

    window.addEventListener('click', (e) => {
        if (e.target === modal) {
            closeModal();
        }
    });

    // Status Toggle Handler
    document.querySelectorAll('.status-toggle').forEach(toggle => {
        toggle.addEventListener('change', function () {
            const id = this.getAttribute('data-id');
            const status = this.checked ? 'Active' : 'Inactive';

            fetch('handlers/update_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ id, status })
            })
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        alert('Error updating status: ' + data.error);
                        this.checked = !this.checked; // Revert switch
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while updating status');
                    this.checked = !this.checked; // Revert switch
                });
        });
    });

    // Clear messages from URL after 3 seconds
    if (window.location.search.includes('success=1') || window.location.search.includes('error=duplicate')) {
        if (window.location.search.includes('error=duplicate')) {
            alert('Error: Mobile number already exists!');
        }

        setTimeout(() => {
            const url = new URL(window.location);
            url.searchParams.delete('success');
            url.searchParams.delete('error');
            window.history.replaceState({}, document.title, url.pathname + url.search);
        }, 3000);
    }
</script>

<?php include 'includes/app_footer.php'; ?>