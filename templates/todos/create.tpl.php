<div class="dashboard-container">
    <header class="dashboard-header">
        <h1><?= $is_edit ? 'Edit Todo' : 'Create New Todo' ?></h1>
    </header>

    <div class="card">
        <form action="/todos/create.php" method="POST" class="create-todo-form">
            <?php if ($is_edit): ?>
                <input type="hidden" name="todo_id" value="<?= $todo['todo_id'] ?>">
            <?php endif; ?>

            <div class="form-group">
                <label for="title">Title <span class="required">*</span></label>
                <input type="text" id="title" name="title" required class="form-control" placeholder="e.g., Morning Meditation" value="<?= htmlspecialchars($todo['title'] ?? '') ?>">
            </div>

            <div class="form-group" style="display:none;">
                <label for="description">Description</label>
                <textarea id="description" name="description" class="form-control" rows="3" placeholder="Optional details..."><?= htmlspecialchars($todo['description'] ?? '') ?></textarea>
            </div>

            <fieldset class="form-section">
                <legend>Activity Type (Optional)</legend>
                <div class="form-group">
                    <label for="activity_id">Select</label>
                    <select id="activity_id" name="activity_id" class="form-control">
                        <option value="">-- None --</option>
                        <?php foreach ($activities as $activity): ?>
                            <option value="<?= $activity['activity_id'] ?>" <?= (isset($todo) && $todo['activity_id'] == $activity['activity_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($activity['activity_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="help-text">This activity will be selected when you start this todo.</small>
                </div>
            </fieldset>

            <fieldset class="form-section">
                <legend>Goal</legend>
                <!-- Timer is always on by default now (we *always* see a start button (which can be ignored)) -->
                <input type="hidden" name="is_timer" id="is_timer" value="1">
                <!-- Counter is controlled by JS based on target_count -->
                <input type="checkbox" name="is_counter" id="is_counter" value="1" <?= (isset($todo) && $todo['is_counter']) ? 'checked' : '' ?> style="display:none;">

                <div class="form-row">
                    <div class="form-group">
                        <label for="target_duration_seconds">Ideal Duration (Optional)</label>
                        <div class="duration-input-group">
                            <input type="number" id="target_duration_minutes" class="form-control" placeholder="Minutes" value="<?= isset($todo['target_duration_seconds']) ? floor($todo['target_duration_seconds'] / 60) : '' ?>">
                            <input type="hidden" name="target_duration_seconds" id="target_duration_seconds" value="<?= $todo['target_duration_seconds'] ?? '' ?>">
                        </div>
                        <small class="help-text">Minutes of timed activity</small>
                    </div>

                    <div class="form-group">
                        <label for="target_count">How many per day?</label>
                        <input type="number" id="target_count" name="target_count" value="<?= $todo['target_count'] ?? 1 ?>" min="1" class="form-control">
                        <small class="help-text">Default is 1 (checkbox style)</small>
                    </div>
                </div>
            </fieldset>

            <fieldset class="form-section">
                <legend>Preferred Time</legend>
                <div class="form-group">
                    <label for="do_time">Time of day</label>
                    <input type="time" id="do_time" name="do_time" class="form-control" value="<?= htmlspecialchars($todo['do_time'] ?? '') ?>">
                    <small class="help-text">When this todo appears on your daily list</small>
                </div>
            </fieldset>

            <fieldset class="form-section">
                <legend>Recurrence</legend>

                <div class="form-group">
                    <label>Days of Week</label>
                    <div class="days-checkboxes">
                        <?php
                        $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                        $selected_days = isset($todo['do_days']) ? explode(',', $todo['do_days']) : [];
                        foreach ($days as $day):
                        ?>
                        <label class="checkbox-inline">
                            <input type="checkbox" name="do_days[]" value="<?= $day ?>" <?= in_array($day, $selected_days) ? 'checked' : '' ?>> <?= $day ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label for="do_dates">Dates of Month</label>
                    <input type="text" id="do_dates" name="do_dates" class="form-control" placeholder="e.g., 1, 15, 30" value="<?= htmlspecialchars($todo['do_dates'] ?? '') ?>">
                </div>

                <div class="recurrence-or-divider"><span>or, for a one-time todo</span></div>

                <div class="form-group">
                    <label for="due_date">Due Date</label>
                    <input type="date" id="due_date" name="due_date" class="form-control" value="<?= htmlspecialchars($todo['due_date'] ?? '') ?>">
                </div>
            </fieldset>

            <div class="form-actions">
                <button type="submit" class="btn-primary"><?= $btn_text ?? 'Create Todo' ?></button>
            </div>
            <div class="form-actions">
                <a href="/" class="btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
    // Simple script to handle minute -> second conversion
    const minInput = document.getElementById('target_duration_minutes');
    const secInput = document.getElementById('target_duration_seconds');

    if (minInput && secInput) {
        minInput.addEventListener('input', function() {
            if (this.value) {
                secInput.value = parseInt(this.value) * 60;
            } else {
                secInput.value = '';
            }
        });
    }

    // Auto-check "is_counter" if target_count > 1
    const targetCountInput = document.getElementById('target_count');
    const isCounterCheckbox = document.getElementById('is_counter');

    function updateCounterCheckbox() {
        if (targetCountInput && isCounterCheckbox) {
            const count = parseInt(targetCountInput.value) || 0;
            isCounterCheckbox.checked = count > 1;
        }
    }

    if (targetCountInput) {
        targetCountInput.addEventListener('input', updateCounterCheckbox);
        // Run once on load to set initial state
        updateCounterCheckbox();
    }
</script>

<style>
    .card {
        background: var(--bg-card);
        padding: 2rem;
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-md);
        max-width: 800px;
        margin: 0 auto;
    }
    .form-group { margin-bottom: 1.5rem; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
    .checkbox-group { display: flex; gap: 1rem; margin-bottom: 1rem; }
    .days-checkboxes { display: flex; flex-wrap: wrap; gap: 1rem; }
    .help-text { display: block; font-size: 0.875rem; color: var(--text-muted); margin-top: 0.25rem; }
    .form-section { border: 1px solid var(--border-color); padding: 1.5rem; border-radius: var(--radius-md); margin-bottom: 1.5rem; }
    .form-section legend { padding: 0 0.5rem; font-weight: 600; color: var(--text-primary); }
    .required { color: var(--danger); }
    .form-actions { margin-top: 2rem; display: flex; justify-content: flex-end; }
    .recurrence-or-divider {
        display: flex; align-items: center; gap: 1rem;
        margin: 1rem 0; color: var(--text-muted); font-size: 0.875rem;
    }
    .recurrence-or-divider::before,
    .recurrence-or-divider::after {
        content: ''; flex: 1; height: 1px; background: var(--border-color);
    }
</style>
