<h1>Meiso Gambare Table Migration Dashboard</h1>
<script>
    function applyMigration(migration, buttonElement) {
        // AJAX request to apply the migration
        fetch('/admin/apply_migration.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ migration: migration })
        })
        .then(response => {
            if (response.ok) {
                // Remove the list item from the DOM
                buttonElement.parentElement.remove();
            } else {
                alert("Failed to apply migration. Please try again.");
            }
        })
        .catch(error => {
            console.error("Error applying migration:", error);
            alert("An error occurred while applying the migration.");
        });
    }
</script>
<?php
if ($has_pending_migrations) {
        echo "<h3>Pending DB Migrations</h3><ul>";
        foreach ($pending_migrations as $migration) {
            $safe_html = htmlspecialchars($migration);
            $safe_js   = json_encode($migration);
            echo "<li>{$safe_html} <button onclick=\"applyMigration({$safe_js}, this)\">Apply</button></li>";
        }
        echo "</ul>";
    }
?>

<div class="PagePanel">
    <a href="/admin/">admin</a> <br />
</div>
