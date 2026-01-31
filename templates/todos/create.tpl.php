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
                    <small class="help-text">Completing this activity will complete this todo.</small>
                </div>
            </fieldset>

            <fieldset class="form-section">
                <legend>Type & Goal</legend>

                <div class="checkbox-group">
                    <!-- Timer is always on by default now (we *always* see a start button (which can be ignored)) -->
                    <input type="hidden" name="is_timer" id="is_timer" value="1">
                    <label>
                        <input type="checkbox" name="is_counter" id="is_counter" value="1" <?= (isset($todo) && $todo['is_counter']) ? 'checked' : '' ?>>
                        More than once per day?
                    </label>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="target_duration_seconds">Ideal Duration</label>
                        <div class="duration-input-group">
                            <input type="number" id="target_duration_minutes" class="form-control" placeholder="Minutes" value="<?= isset($todo['target_duration_seconds']) ? floor($todo['target_duration_seconds'] / 60) : '' ?>">
                            <input type="hidden" name="target_duration_seconds" id="target_duration_seconds" value="<?= $todo['target_duration_seconds'] ?? '' ?>">
                        </div>
                        <small class="help-text">(Optional) minutes of timed activity</small>
                    </div>

                    <div class="form-group">
                        <label for="target_count">How many per day?</label>
                        <input type="number" id="target_count" name="target_count" value="<?= $todo['target_count'] ?? 1 ?>" min="1" class="form-control">
                        <small class="help-text">Default is 1 (checkbox style)</small>
                    </div>
                </div>
            </fieldset>



            <fieldset class="form-section">
                <legend>Recurrence</legend>
                <p class="section-intro">Select days or dates to make this a recurring habit. Leave blank for a one-time todo.</p>

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
                    <!-- confusing
                    <small class="help-text">Comma-separated dates (1-31)</small>   -->
                </div>
            </fieldset>

            <fieldset class="form-section">
                <legend>Scheduling</legend>
                <div class="form-row">
                    <div class="form-group">
                        <label for="do_time">Preferred Time</label>
                        <input type="time" id="do_time" name="do_time" class="form-control" value="<?= htmlspecialchars($todo['do_time'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="due_date">Due Date</label>
                        <input type="date" id="due_date" name="due_date" class="form-control" value="<?= htmlspecialchars($todo['due_date'] ?? '') ?>">
                        <small class="help-text">For one-time todos</small>
                    </div>
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

    minInput.addEventListener('input', function() {
        if (this.value) {
            secInput.value = parseInt(this.value) * 60;
        } else {
            secInput.value = '';
        }
    });

    // Validations could be added here
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
</style>
