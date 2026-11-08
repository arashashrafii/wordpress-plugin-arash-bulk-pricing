(function ($) {
  const $apply = $('#abp-apply');
  const $save = $('#abp-save-changes');
  const $status = $('#abp-status');
  const $grid = $('.abp-table-grid');
  const $gridStatus = $('.abp-grid-loading');
  let currentPage = 1;
  let currentTotal = Number($('#abp-pagination-top').data('total')) || 0;
  let currentPerPage = $('#abp-per-page').val() || '10';
  let previousOperation = $('#abp-operation').val();

  function setStatus(message, loading) {
    $status.text(message);
    $gridStatus.toggleClass('is-loading', Boolean(loading)).prop('hidden', !message);
    $grid.attr('aria-busy', loading ? 'true' : 'false');
  }

  function stopStatusLoading() {
    $gridStatus.removeClass('is-loading');
    $grid.attr('aria-busy', 'false');
  }

  function initializePersianDatePickers($scope) {
    const $inputs = $scope.find('.abp-date-picker').addBack('.abp-date-picker');
    const datepicker = window.jalaliDatepicker;
    if (!datepicker || typeof datepicker.startWatch !== 'function') {
      $inputs.prop('readonly', false).attr('inputmode', 'numeric');
      $inputs.each(function () {
        const $input = $(this);
        const placeholder = $input.attr('placeholder') || 'تاریخ شمسی';
        if (placeholder.indexOf('YYYY/MM/DD') === -1) {
          $input.attr('placeholder', placeholder + ' (YYYY/MM/DD)');
        }
      });
      return;
    }
    $inputs.attr('data-jdp', '').attr('inputmode', 'numeric');
    if (!window.abpJalaliDatepickerStarted) {
      datepicker.startWatch({
        selector: '.abp-date-picker',
        persianDigits: false,
        autoReadOnlyInput: false,
        position: 'center',
        zIndex: 100100
      });
      window.abpJalaliDatepickerStarted = true;
    }
  }

  function setDateFieldValue($input, value) {
    $input.val(value || '').trigger('input').trigger('change');
  }

  function syncFilterValueFields() {
    const isPeriod = $('#abp-filters select[name="filter_column"]').val() === 'sale_period';
    const $dateValues = $('#abp-filter-date-values');
    $('#abp-filters select[name="filter_operator"]').prop('disabled', isPeriod).prop('hidden', isPeriod);
    $('#abp-filter-value').prop('disabled', isPeriod).toggle(!isPeriod);
    $dateValues.prop('hidden', !isPeriod).find('input').prop('disabled', !isPeriod);
  }

  function syncOperationValueInput() {
    const operation = $('#abp-operation').val();
    const $value = $('#abp-value');
    if (operation === 'round') {
      if (previousOperation !== 'round') $value.val('1000');
      $value.attr({ min: '1', step: '1', placeholder: 'مبلغ گردکردن (مثلاً ۱۰۰۰)' });
    } else {
      if (previousOperation === 'round') $value.val('');
      $value.attr({ min: '0', step: '0.01', placeholder: operation === 'set' ? 'مبلغ' : 'درصد' });
    }
    previousOperation = operation;
  }

  function selectedRows() {
    const selected = [];
    $('.abp-row:checked:not(:disabled)').each(function () {
      selected.push($(this).closest('tr'));
    });
    return selected;
  }

  function values($tr) {
    return {
      regular: $tr.find('[data-field="regular"]').val(),
      sale: $tr.find('[data-field="sale"]').val(),
      start: $tr.find('[data-field="start"]').val(),
      end: $tr.find('[data-field="end"]').val()
    };
  }

  function isDirty($tr) {
    const current = values($tr);
    return Object.keys(current).some(function (key) {
      return String(current[key] || '') !== String($tr.attr('data-original-' + key) || '');
    });
  }

  function refreshDirty() {
    let count = 0;
    $('.abp-table tbody tr[data-id]').each(function () {
      const $tr = $(this);
      const dirty = isDirty($tr);
      $tr.toggleClass('abp-dirty', dirty);
      $tr.find('.abp-save-row').prop('disabled', !dirty);
      if (dirty) count++;
    });
    $save.prop('disabled', count === 0);
    $save.text(count ? 'ذخیره تغییرات (' + count + ')' : 'ذخیره تغییرات');
  }

  function toggleSelection() {
    $apply.prop('disabled', selectedRows().length === 0);
  }

  function initializeRows() {
    $('.abp-table tbody tr[data-id]').each(function () {
      const $tr = $(this);
      const initial = values($tr);
      $tr.attr({
        'data-original-regular': initial.regular || '',
        'data-original-sale': initial.sale || '',
        'data-original-start': initial.start || '',
        'data-original-end': initial.end || ''
      });
    });
    refreshDirty();
    toggleSelection();
  }

  function renderPagination() {
    const pageSize = currentPerPage === 'all' ? Math.max(currentTotal, 1) : Number(currentPerPage);
    const pageCount = currentPerPage === 'all' ? 1 : Math.max(1, Math.ceil(currentTotal / pageSize));
    const label = currentTotal.toLocaleString('fa-IR') + ' ردیف';
    if (pageCount <= 1) {
      $('#abp-pagination-top, #abp-pagination-bottom').html('<span>' + label + '</span>');
      return;
    }
    const controls =
      '<button type="button" class="button" data-abp-page="' + Math.max(1, currentPage - 1) + '" ' + (currentPage <= 1 ? 'disabled' : '') + '>قبلی</button>' +
      '<span>صفحه ' + currentPage.toLocaleString('fa-IR') + ' از ' + pageCount.toLocaleString('fa-IR') + ' — ' + label + '</span>' +
      '<button type="button" class="button" data-abp-page="' + Math.min(pageCount, currentPage + 1) + '" ' + (currentPage >= pageCount ? 'disabled' : '') + '>بعدی</button>';
    $('#abp-pagination-top, #abp-pagination-bottom').html(controls);
  }

  function hasUnsavedChanges() {
    return $('.abp-table tbody tr[data-id]').filter(function () { return isDirty($(this)); }).length > 0;
  }

  function confirmDiscard() {
    return !hasUnsavedChanges() || window.confirm('تغییرات ذخیره‌نشده دارید. با ادامه، این تغییرات کنار گذاشته می‌شوند. ادامه می‌دهید؟');
  }

  function loadProducts(page, perPage) {
    const data = { action: 'arash_bulk_price_filter', nonce: ArashBulkPricing.nonce, page: page, per_page: perPage };
    $('#abp-filters').serializeArray().forEach(function (item) { data[item.name] = item.value; });
    setStatus('در حال دریافت اطلاعات…', true);
    $('#abp-filters button[type="submit"]').prop('disabled', true);
    $.post(ArashBulkPricing.ajaxUrl, data).done(function (response) {
      if (!response.success) {
        setStatus(response.data && response.data.message ? response.data.message : 'خطا در دریافت محصولات.');
        return;
      }
      $('.abp-table tbody').html(response.data.html);
      initializePersianDatePickers($('.abp-table tbody'));
      currentTotal = Number(response.data.total) || 0;
      currentPage = Number(response.data.page) || 1;
      currentPerPage = response.data.per_page || '10';
      $('#abp-per-page').val(currentPerPage);
      $('#abp-select-all').prop('checked', false);
      initializeRows();
      renderPagination();
      setStatus('');
    }).fail(function () {
      setStatus('خطا در دریافت محصولات.');
    }).always(function () {
      stopStatusLoading();
      $('#abp-filters button[type="submit"]').prop('disabled', false);
    });
  }

  function setOriginalValues($tr, row) {
    $tr.find('[data-field="regular"]').val(row.regular);
    $tr.find('[data-field="sale"]').val(row.sale);
    $tr.find('[data-field="start"]').val(row.start);
    $tr.find('[data-field="end"]').val(row.end);
    $tr.attr({
      'data-original-regular': row.regular || '',
      'data-original-sale': row.sale || '',
      'data-original-start': row.start || '',
      'data-original-end': row.end || ''
    });
  }

  function collectRows($trs) {
    return $trs.map(function () {
      const $tr = $(this);
      return $.extend({ id: Number($tr.data('id')) }, values($tr));
    }).get();
  }

  function saveRows($trs, button) {
    const rows = collectRows($trs);
    if (!rows.length) return;
    if (button) button.prop('disabled', true);
    setStatus('در حال ذخیره…', true);
    $.post(ArashBulkPricing.ajaxUrl, {
      action: 'arash_bulk_price_update',
      nonce: ArashBulkPricing.nonce,
      mode: 'save_rows',
      rows: JSON.stringify(rows)
    }).done(function (response) {
      if (!response.success) {
        setStatus(response.data && response.data.message ? response.data.message : 'خطا در ذخیره.');
        return;
      }
      response.data.rows.forEach(function (row) {
        setOriginalValues($('.abp-table tbody tr[data-id="' + row.id + '"]'), row);
      });
      setStatus('تغییرات ذخیره شد.');
      refreshDirty();
    }).fail(function (xhr) {
      const message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
      setStatus(message || 'خطا در ذخیره تغییرات.');
    }).always(function () {
      stopStatusLoading();
      if (button) button.prop('disabled', false);
      refreshDirty();
    });
  }

  $('#abp-select-all').on('change', function () {
    $('.abp-row:not(:disabled)').prop('checked', this.checked);
    toggleSelection();
  });
  $('.abp-table').on('change', '.abp-row', toggleSelection);
  $('.abp-table').on('input change', '[data-field]', refreshDirty);
  $('.abp-table').on('click', '.abp-save-row', function () {
    const $tr = $(this).closest('tr');
    saveRows($tr, $(this));
  });

  $('#abp-filters').on('submit', function (event) {
    event.preventDefault();
    if (confirmDiscard()) loadProducts(1, currentPerPage);
  });
  $('#abp-filters select[name="filter_column"]').on('change', syncFilterValueFields);
  $('#abp-per-page').on('change', function () {
    const next = $(this).val();
    if (next === 'all' && currentTotal > 50 && !window.confirm('نمایش همهٔ ' + currentTotal.toLocaleString('fa-IR') + ' ردیف ممکن است زمان‌بر شود. ادامه می‌دهید؟')) {
      $(this).val(currentPerPage);
      return;
    }
    if (!confirmDiscard()) {
      $(this).val(currentPerPage);
      return;
    }
    loadProducts(1, next);
  });
  $('.abp-pagination').on('click', '[data-abp-page]', function () {
    const page = Number($(this).data('abp-page'));
    if (page !== currentPage && confirmDiscard()) loadProducts(page, currentPerPage);
  });

  $('#abp-apply').on('click', function () {
    const rows = selectedRows();
    if (!rows.length) return;

    const field = $('#abp-field').val();
    const operation = $('#abp-operation').val();
    const value = Number($('#abp-value').val());
    const isPercentage = operation === 'increase' || operation === 'decrease';
    const isRound = operation === 'round';
    if (operation !== 'clear' && (!Number.isFinite(value) || value < (isRound ? 1 : 0) || (isPercentage && value > 100))) {
      setStatus('لطفاً مبلغ یا درصد معتبر وارد کنید.');
      return;
    }

    rows.forEach(function ($tr) {
      const current = values($tr);
      let next = '';
      if (operation === 'clear') {
        next = '';
      } else if (operation === 'set') {
        next = value;
      } else if (operation === 'round') {
        const currentPrice = Number(current[field]);
        if (!Number.isFinite(currentPrice) || current[field] === '') return;
        next = Math.round(currentPrice / value) * value;
      } else {
        const base = Number(current.regular);
        if (!Number.isFinite(base) || current.regular === '') return;
        next = base * (1 + (operation === 'increase' ? 1 : -1) * value / 100);
      }

      $tr.find('[data-field="' + field + '"]').val(next === '' ? '' : Number(next.toFixed(2)));
      if (field === 'sale') {
        const start = $('#abp-start').val();
        const end = $('#abp-end').val();
        if (operation === 'clear') {
          setDateFieldValue($tr.find('[data-field="start"]'), '');
          setDateFieldValue($tr.find('[data-field="end"]'), '');
        } else {
          if (start) setDateFieldValue($tr.find('[data-field="start"]'), start);
          if (end) setDateFieldValue($tr.find('[data-field="end"]'), end);
        }
      }
    });

    refreshDirty();
    setStatus('مقادیر در باکس‌های ردیف‌های انتخابی اعمال شد؛ برای ثبت در پایگاه‌داده «ذخیره تغییرات» را بزنید.');
  });

  $('#abp-save-changes').on('click', function () {
    const $dirty = $('.abp-table tbody tr[data-id]').filter(function () { return isDirty($(this)); });
    saveRows($dirty, $(this));
  });

  syncFilterValueFields();
  $('#abp-operation').on('change', syncOperationValueInput);
  syncOperationValueInput();
  initializePersianDatePickers($('.abp-operation-row, #abp-filter-date-values, .abp-table tbody'));
  initializeRows();
  renderPagination();
})(jQuery);
