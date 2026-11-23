<?php

/**
 * The Images card: a strip of pictures, newest upload on the left, scrolling
 * sideways. On an issue page a writer also gets the upload form and a remove
 * button under each picture; on a project page each picture names its issue.
 *
 * @var list<\Issues\Images\ImageView> $images
 * @var bool $can_write
 * @var int|null $issue_id the issue this strip belongs to; null on a project page
 * @var list<string> $notes what the last upload has to say, one line each
 * @var int $max_files
 * @var int $max_bytes
 */

$show_form = $can_write && $issue_id !== null;
?>
<?php if ($images !== [] || $show_form): ?>
    <div class="card" id="images">
        <h2>Images <?php if ($images !== []): ?><span class="image-count">(<?= count($images) ?>)</span><?php endif; ?></h2>

        <?php if ($notes !== []): ?>
            <ul class="image-notes">
                <?php foreach ($notes as $note): ?>
                    <li><?= htmlspecialchars($note) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($show_form): ?>
            <form method="POST" action="/issues/images.php" enctype="multipart/form-data" class="image-add-form"
                  data-max-files="<?= $max_files ?>" data-max-bytes="<?= $max_bytes ?>">
                <?= $csrf->field() ?>
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="issue_id" value="<?= $issue_id ?>">
                <label for="image-files" class="visually-hidden">Pictures to add</label>
                <input type="file" id="image-files" name="images[]" multiple required
                       accept="image/png,image/jpeg,image/gif,image/webp">
                <button type="submit" class="btn-sm">Add images</button>
                <p class="image-add-problem" role="alert" hidden></p>
            </form>
        <?php endif; ?>

        <?php if ($images !== []): ?>
            <div class="image-strip">
                <?php foreach ($images as $view): ?>
                    <?php $image = $view->image; ?>
                    <figure class="image-strip-item">
                        <a href="<?= htmlspecialchars($view->url) ?>" target="_blank" rel="noopener">
                            <img src="<?= htmlspecialchars($view->url) ?>"
                                 alt="<?= htmlspecialchars($image->filename) ?>"
                                 width="<?= $image->width ?>" height="<?= $image->height ?>" loading="lazy">
                        </a>
                        <figcaption>
                            <span class="image-name" title="<?= htmlspecialchars($image->filename) ?>"><?= htmlspecialchars($image->filename) ?></span>
                            <div class="image-actions">
                                <?php if ($issue_id === null): ?>
                                    <a href="/issues/view.php?issue_id=<?= $image->homeIssueId ?>#images">#<?= $image->homeIssueId ?></a>
                                <?php endif; ?>
                                <button type="button" class="btn-sm image-copy"
                                        data-markdown="<?= htmlspecialchars($view->markdown) ?>">copy</button>
                                <?php if ($show_form): ?>
                                    <form method="POST" action="/issues/images.php" class="image-remove-form">
                                        <?= $csrf->field() ?>
                                        <input type="hidden" name="action" value="remove">
                                        <input type="hidden" name="issue_id" value="<?= $issue_id ?>">
                                        <input type="hidden" name="issue_image_id" value="<?= $image->issueImageId ?>">
                                        <button type="submit" class="btn-sm">remove</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </figcaption>
                    </figure>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <script src="/js/<?= SEMVER ?>/issue-images.js" defer></script>
<?php endif; ?>
