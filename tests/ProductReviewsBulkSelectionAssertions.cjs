const assert = require('node:assert/strict')
const fs = require('node:fs')
const path = require('node:path')
const test = require('node:test')
const { Window } = require('happy-dom')

const templatePath = path.join(__dirname, '..', 'plugins', 'LongosProductReviews', 'Views', 'admin', 'index.blade.php')

function renderedSelectionScript () {
  const template = fs.readFileSync(templatePath, 'utf8')
  const footer = template.slice(template.indexOf("@push('footer')"))
  const script = footer.match(/<script>([\s\S]*?)<\/script>/)

  assert.ok(script, 'The product reviews footer script should exist')

  return script[1]
    .replace(/@json\(trans\('LongosProductReviews::reviews\.selected_count',[\s\S]*?\)\);/, '"__COUNT__ selected";')
    .replace(/@json\(trans\('LongosProductReviews::reviews\.ai_batch_progress',[\s\S]*?\)\);/, '"__PROCESSED__ of __TOTAL__ processed";')
}

test('product review bulk selection script is valid JavaScript', () => {
  assert.doesNotThrow(() => new Function(renderedSelectionScript()))
})

test('select all and individual review selection keep controls in sync', () => {
  const window = new Window({ url: 'https://longos.test/admin/product-reviews' })
  window.document.body.innerHTML = `
    <button id="review-bulk-submit" type="submit" disabled>Apply</button>
    <span id="review-selection-status">0 selected</span>
    <input id="review-select-all" type="checkbox">
    <input class="review-row-select" type="checkbox" name="review_ids[]" value="1">
    <input class="review-row-select" type="checkbox" name="review_ids[]" value="2">
  `

  window.eval(renderedSelectionScript())

  const selectAll = window.document.getElementById('review-select-all')
  const selections = [...window.document.querySelectorAll('.review-row-select')]
  const submit = window.document.getElementById('review-bulk-submit')
  const status = window.document.getElementById('review-selection-status')

  selectAll.checked = true
  selectAll.dispatchEvent(new window.Event('change'))

  assert.equal(selections.every((checkbox) => checkbox.checked), true)
  assert.equal(status.textContent, '2 selected')
  assert.equal(submit.disabled, false)

  selections[0].checked = false
  selections[0].dispatchEvent(new window.Event('change'))

  assert.equal(selectAll.checked, false)
  assert.equal(selectAll.indeterminate, true)
  assert.equal(status.textContent, '1 selected')
  assert.equal(submit.disabled, false)

  selections[1].checked = false
  selections[1].dispatchEvent(new window.Event('change'))

  assert.equal(selectAll.indeterminate, false)
  assert.equal(status.textContent, '0 selected')
  assert.equal(submit.disabled, true)

  window.close()
})
