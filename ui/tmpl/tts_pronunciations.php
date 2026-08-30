<?php
// Pronunciations tab for TTS Studio. Rendered by ui/xtts_clone.php, which resolves the state below.
$pronWebRoot = rtrim(strval($webRoot ?? ''), '/');
$pronRows = is_array($ttsPronunciationRows ?? null) ? $ttsPronunciationRows : [];
$pronTags = is_array($ttsPronunciationTags ?? null) ? $ttsPronunciationTags : [];
$pronFilter = strval($ttsPronunciationFilter ?? '');
$pronAvailable = !empty($ttsPronunciationAvailable);
$pronEditRow = is_array($ttsPronunciationEditRow ?? null) ? $ttsPronunciationEditRow : null;
$pronNotice = is_array($ttsPronunciationNotice ?? null) ? $ttsPronunciationNotice : null;
$pronEmbedded = !empty($isEmbed);

$pronBaseUrl = $pronWebRoot . '/ui/xtts_clone.php?tab=pronunciations' . ($pronEmbedded ? '&embed=1' : '');
$pronListUrl = $pronBaseUrl . ($pronFilter !== '' ? '&oghma_tag=' . rawurlencode($pronFilter) : '');

$pronEditId = $pronEditRow !== null ? intval($pronEditRow['id'] ?? 0) : 0;
$pronSourceValue = $pronEditRow !== null ? strval($pronEditRow['source_text'] ?? '') : '';
$pronSpokenValue = $pronEditRow !== null ? strval($pronEditRow['spoken_text'] ?? '') : '';
$pronNamesValue = $pronEditRow !== null ? strval($pronEditRow['npc_names'] ?? '') : '';
$pronRacesValue = $pronEditRow !== null ? strval($pronEditRow['races'] ?? '') : '';
$pronTagsValue = $pronEditRow !== null ? strval($pronEditRow['oghma_tags'] ?? '') : '';
$pronEnabledValue = $pronEditRow === null || dialecticTtsPronunciationBoolean($pronEditRow['enabled'] ?? true);
$pronDisabledAttr = $pronAvailable ? '' : ' disabled';

// Collect only the populated access dimensions so a row never claims a filter it does not use.
$pronScopeGroups = static function (array $row): array {
    $groups = [];
    $names = dialecticTtsPronunciationNormalizeScopeValues($row['npc_names'] ?? '');
    if (!empty($names)) {
        $groups[] = ['label' => 'NPC', 'values' => $names];
    }
    $races = dialecticTtsPronunciationNormalizeScopeValues($row['races'] ?? '');
    if (!empty($races)) {
        $groups[] = ['label' => 'Race', 'values' => $races];
    }
    $tags = dialecticTtsPronunciationNormalizeTags($row['oghma_tags'] ?? '');
    if (!empty($tags)) {
        $groups[] = ['label' => 'Oghma tag', 'values' => $tags];
    }
    return $groups;
};

$pronCustomCount = 0;
$pronBuiltinCount = 0;
foreach ($pronRows as $pronCountRow) {
    if (dialecticTtsPronunciationBoolean($pronCountRow['is_builtin'] ?? false)) {
        $pronBuiltinCount++;
    } else {
        $pronCustomCount++;
    }
}
?>
<div class="content-section full-width-section tts-pron-section">
    <h1>Pronunciation Dictionary</h1>
    <p class="tts-pron-lede">
        Rewrite how words are spoken. Replacements apply only to the text sent to the TTS voice &mdash;
        subtitles and saved dialogue keep the original spelling.
    </p>
    <ul class="tts-pron-notes">
        <li>Leave <strong>NPC names</strong>, <strong>races</strong>, and <strong>Oghma tags</strong> blank and the entry applies to every speaker.</li>
        <li>Commas inside one field are alternatives &mdash; <code>Human, Ghoul</code> matches either race.</li>
        <li>Fill more than one field and the speaker must match <strong>all</strong> of them, so <code>Ghoul</code> plus <code>ncr</code> only fires for a ghoul carrying that tag.</li>
        <li>Built-in entries can be enabled or disabled, but not edited or deleted.</li>
    </ul>

    <?php if ($pronNotice !== null): ?>
        <p class="tts-pron-notice tts-pron-notice-<?php echo htmlspecialchars(strval($pronNotice['tone'] ?? 'success')); ?>"
           role="<?php echo (strval($pronNotice['tone'] ?? '') === 'error') ? 'alert' : 'status'; ?>">
            <?php echo htmlspecialchars(strval($pronNotice['text'] ?? '')); ?>
        </p>
    <?php endif; ?>

    <?php if (!$pronAvailable): ?>
        <p class="tts-pron-notice tts-pron-notice-error" role="alert">
            The pronunciation table has not been created yet. Built-in entries are listed read-only until the
            database update runs.
        </p>
    <?php endif; ?>

    <form id="tts-pron-editor" class="tts-pron-form" method="post" action="<?php echo htmlspecialchars($pronListUrl); ?>">
        <h2 class="tts-pron-subhead"><?php echo $pronEditId > 0 ? 'Edit pronunciation' : 'Add a pronunciation'; ?></h2>
        <input type="hidden" name="action" value="save_tts_pronunciation">
        <input type="hidden" name="oghma_tag" value="<?php echo htmlspecialchars($pronFilter); ?>">
        <?php if ($pronEditId > 0): ?>
            <input type="hidden" name="id" value="<?php echo $pronEditId; ?>">
        <?php endif; ?>

        <div class="tts-pron-form-grid">
            <div class="tts-pron-field">
                <label for="tts-pron-source">Written form</label>
                <input type="text" id="tts-pron-source" name="source_text" maxlength="120" required
                       placeholder="Mojave" aria-describedby="tts-pron-source-hint"
                       value="<?php echo htmlspecialchars($pronSourceValue); ?>"<?php echo $pronDisabledAttr; ?>>
                <span class="tts-pron-hint" id="tts-pron-source-hint">Spelling used in dialogue, for example <code>Mojave</code>.</span>
            </div>
            <div class="tts-pron-field">
                <label for="tts-pron-spoken">Spoken form</label>
                <input type="text" id="tts-pron-spoken" name="spoken_text" maxlength="240" required
                       placeholder="Mo-hah-vee" aria-describedby="tts-pron-spoken-hint"
                       value="<?php echo htmlspecialchars($pronSpokenValue); ?>"<?php echo $pronDisabledAttr; ?>>
                <span class="tts-pron-hint" id="tts-pron-spoken-hint">What the voice should say, for example <code>Mo-hah-vee</code>.</span>
            </div>
        </div>

        <fieldset class="tts-pron-access">
            <legend class="tts-pron-access-legend">Who hears it <span class="tts-pron-optional">(all optional)</span></legend>
            <p class="tts-pron-hint tts-pron-access-note" id="tts-pron-access-note">
                A blank field adds no restriction. Fill two or three and the speaker must match every one of them.
            </p>
            <div class="tts-pron-form-grid">
                <div class="tts-pron-field">
                    <label for="tts-pron-names">NPC names</label>
                    <input type="text" id="tts-pron-names" name="npc_names" maxlength="512"
                           placeholder="Boone, Veronica" aria-describedby="tts-pron-names-hint tts-pron-access-note"
                           value="<?php echo htmlspecialchars($pronNamesValue); ?>"<?php echo $pronDisabledAttr; ?>>
                    <span class="tts-pron-hint" id="tts-pron-names-hint">Comma separated, for example <code>Boone, Veronica</code>.</span>
                </div>
                <div class="tts-pron-field">
                    <label for="tts-pron-races">Races</label>
                    <input type="text" id="tts-pron-races" name="races" maxlength="512"
                           placeholder="Human, Ghoul" aria-describedby="tts-pron-races-hint tts-pron-access-note"
                           value="<?php echo htmlspecialchars($pronRacesValue); ?>"<?php echo $pronDisabledAttr; ?>>
                    <span class="tts-pron-hint" id="tts-pron-races-hint">Comma separated, for example <code>Human, Ghoul</code>.</span>
                </div>
                <div class="tts-pron-field">
                    <label for="tts-pron-tags">Oghma tags</label>
                    <input type="text" id="tts-pron-tags" name="oghma_tags" maxlength="512"
                           placeholder="ncr, mojave" aria-describedby="tts-pron-tags-hint tts-pron-access-note"
                           value="<?php echo htmlspecialchars($pronTagsValue); ?>"<?php echo $pronDisabledAttr; ?>>
                    <span class="tts-pron-hint" id="tts-pron-tags-hint">Comma separated, for example <code>ncr, mojave</code>.</span>
                </div>
            </div>
        </fieldset>

        <div class="tts-pron-form-actions">
            <label class="tts-pron-check" for="tts-pron-enabled">
                <input type="checkbox" id="tts-pron-enabled" name="enabled" value="1"
                       <?php echo $pronEnabledValue ? 'checked' : ''; ?><?php echo $pronDisabledAttr; ?>>
                <span>Enabled</span>
            </label>
            <button type="submit" class="action-button add-new"<?php echo $pronDisabledAttr; ?>>
                <?php echo $pronEditId > 0 ? 'Save changes' : 'Add pronunciation'; ?>
            </button>
            <?php if ($pronEditId > 0): ?>
                <a class="action-button tts-pron-cancel" href="<?php echo htmlspecialchars($pronListUrl); ?>">Cancel edit</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="content-section full-width-section tts-pron-section">
    <h1>Dictionary Entries</h1>

    <form class="tts-pron-filter" method="get" action="<?php echo htmlspecialchars($pronWebRoot . '/ui/xtts_clone.php'); ?>">
        <input type="hidden" name="tab" value="pronunciations">
        <?php if ($pronEmbedded): ?>
            <input type="hidden" name="embed" value="1">
        <?php endif; ?>
        <div class="tts-pron-field tts-pron-filter-field">
            <label for="tts-pron-filter">Filter custom entries by Oghma tag</label>
            <select id="tts-pron-filter" name="oghma_tag" aria-describedby="tts-pron-filter-hint"<?php echo empty($pronTags) ? ' disabled' : ''; ?>>
                <option value="">All custom entries</option>
                <?php foreach ($pronTags as $pronTag): ?>
                    <option value="<?php echo htmlspecialchars($pronTag); ?>"<?php echo $pronTag === $pronFilter ? ' selected' : ''; ?>><?php echo htmlspecialchars($pronTag); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="tts-pron-filter-actions">
            <button type="submit" class="action-button"<?php echo empty($pronTags) ? ' disabled' : ''; ?>>Apply filter</button>
            <?php if ($pronFilter !== ''): ?>
                <a class="action-button tts-pron-cancel" href="<?php echo htmlspecialchars($pronBaseUrl); ?>">Clear filter</a>
            <?php endif; ?>
        </div>
        <p class="tts-pron-hint tts-pron-filter-hint" id="tts-pron-filter-hint">
            <?php if (empty($pronTags)): ?>
                No custom entry carries an Oghma tag yet.
            <?php else: ?>
                Built-in entries stay visible whichever tag you pick.
            <?php endif; ?>
        </p>
    </form>

    <div class="tts-pron-table-wrap">
        <table class="tts-pron-table">
            <caption class="tts-pron-caption">
                <?php echo $pronCustomCount; ?> custom and <?php echo $pronBuiltinCount; ?> built-in
                <?php echo ($pronCustomCount + $pronBuiltinCount) === 1 ? 'entry' : 'entries'; ?> shown<?php
                    echo $pronFilter !== '' ? ', filtered by tag ' . htmlspecialchars($pronFilter) : ''; ?>.
            </caption>
            <thead>
                <tr>
                    <th scope="col">Written form</th>
                    <th scope="col">Spoken form</th>
                    <th scope="col">Applies to</th>
                    <th scope="col">Status</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pronRows as $pronRow): ?>
                    <?php
                    $pronRowId = intval($pronRow['id'] ?? 0);
                    $pronRowBuiltin = dialecticTtsPronunciationBoolean($pronRow['is_builtin'] ?? false);
                    $pronRowEnabled = dialecticTtsPronunciationBoolean($pronRow['enabled'] ?? true);
                    $pronRowSource = strval($pronRow['source_text'] ?? '');
                    $pronRowSpoken = strval($pronRow['spoken_text'] ?? '');
                    $pronRowGroups = $pronScopeGroups($pronRow);
                    $pronRowEditing = $pronRowId > 0 && $pronRowId === $pronEditId;
                    $pronRowActionable = $pronAvailable && $pronRowId > 0;
                    ?>
                    <tr class="<?php echo $pronRowEditing ? 'is-editing' : ''; ?>"<?php echo $pronRowEditing ? ' aria-current="true"' : ''; ?>>
                        <td data-label="Written form">
                            <span class="tts-pron-source"><?php echo htmlspecialchars($pronRowSource); ?></span>
                            <span class="tts-pron-badge <?php echo $pronRowBuiltin ? 'is-builtin' : 'is-custom'; ?>"><?php echo $pronRowBuiltin ? 'Built-in' : 'Custom'; ?></span>
                        </td>
                        <td data-label="Spoken form"><?php echo htmlspecialchars($pronRowSpoken); ?></td>
                        <td data-label="Applies to">
                            <?php if (empty($pronRowGroups)): ?>
                                <span class="tts-pron-scope-global">Every speaker</span>
                            <?php else: ?>
                                <?php foreach ($pronRowGroups as $pronRowGroup): ?>
                                    <div class="tts-pron-scope-line">
                                        <span class="tts-pron-scope-label"><?php echo htmlspecialchars($pronRowGroup['label']); ?></span>
                                        <span class="tts-pron-tags">
                                            <?php foreach ($pronRowGroup['values'] as $pronRowValue): ?>
                                                <span class="tts-pron-tag"><?php echo htmlspecialchars($pronRowValue); ?></span>
                                            <?php endforeach; ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (count($pronRowGroups) > 1): ?>
                                    <span class="tts-pron-scope-and">All of these must match.</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td data-label="Status">
                            <span class="tts-pron-status <?php echo $pronRowEnabled ? 'is-on' : 'is-off'; ?>"><?php echo $pronRowEnabled ? 'Enabled' : 'Disabled'; ?></span>
                        </td>
                        <td data-label="Actions">
                            <div class="tts-pron-row-actions">
                                <?php if ($pronRowActionable): ?>
                                    <form method="post" action="<?php echo htmlspecialchars($pronListUrl); ?>">
                                        <input type="hidden" name="action" value="toggle_tts_pronunciation">
                                        <input type="hidden" name="id" value="<?php echo $pronRowId; ?>">
                                        <input type="hidden" name="enabled" value="<?php echo $pronRowEnabled ? '0' : '1'; ?>">
                                        <input type="hidden" name="oghma_tag" value="<?php echo htmlspecialchars($pronFilter); ?>">
                                        <button type="submit" class="tts-pron-btn"
                                                aria-label="<?php echo $pronRowEnabled ? 'Disable' : 'Enable'; ?> pronunciation for <?php echo htmlspecialchars($pronRowSource); ?>"><?php echo $pronRowEnabled ? 'Disable' : 'Enable'; ?></button>
                                    </form>
                                <?php endif; ?>

                                <?php if (!$pronRowBuiltin && $pronRowActionable): ?>
                                    <a class="tts-pron-btn"
                                       href="<?php echo htmlspecialchars($pronListUrl . '&edit=' . $pronRowId); ?>#tts-pron-editor"
                                       aria-label="Edit pronunciation for <?php echo htmlspecialchars($pronRowSource); ?>">Edit</a>
                                    <form method="post" action="<?php echo htmlspecialchars($pronListUrl); ?>">
                                        <input type="hidden" name="action" value="delete_tts_pronunciation">
                                        <input type="hidden" name="id" value="<?php echo $pronRowId; ?>">
                                        <input type="hidden" name="oghma_tag" value="<?php echo htmlspecialchars($pronFilter); ?>">
                                        <button type="submit" class="tts-pron-btn tts-pron-btn-danger"
                                                aria-label="Delete pronunciation for <?php echo htmlspecialchars($pronRowSource); ?>">Delete</button>
                                    </form>
                                <?php elseif ($pronRowBuiltin): ?>
                                    <span class="tts-pron-locked">Cannot edit or delete</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (empty($pronRows)): ?>
                    <tr>
                        <td class="tts-pron-empty" colspan="5">No pronunciations to show.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
