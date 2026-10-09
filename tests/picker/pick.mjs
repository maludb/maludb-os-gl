// The record picker through a browser: open the field's modal, search, take the first row with Enter, wait for the dialog to close; answers the chosen label.
// `id` is the hidden input's id with its #; the opener is `${id}-open` (design-system references/record-picker.md). Shared by the slice browser proofs.
export async function pickRecord(page, id, text) {
  await page.click(id + '-open');
  await page.waitForSelector('#record-picker.show');
  await page.waitForSelector('#record-picker-list .record-picker-row, #record-picker-empty');
  await page.fill('#record-picker-search', text);
  await page.waitForTimeout(700);
  await page.waitForSelector('#record-picker .record-picker-row');
  await page.keyboard.press('Enter');
  await page.waitForSelector('#record-picker', { state: 'hidden' });
  return (await page.textContent(id + '-open')).trim();
}
