(function () {
  'use strict';

  var modal = null;
  var mainImage = null;
  var thumbs = null;
  var titleNode = null;
  var statusNode = null;
  var previousButton = null;
  var nextButton = null;
  var images = [];
  var activeIndex = 0;
  var returnFocus = null;
  var savedScrollY = 0;
  var initialized = false;

  function sampleJsonUrl(url) {
    try {
      var parsed = new URL(url, window.location.href);
      parsed.searchParams.set('format', 'json');
      return parsed.toString();
    } catch (error) {
      return url + (url.indexOf('?') === -1 ? '?' : '&') + 'format=json';
    }
  }

  function buildModal() {
    if (modal) return;
    modal = document.createElement('div');
    modal.className = 'sample-image-modal';
    modal.setAttribute('aria-hidden', 'true');
    modal.innerHTML =
      '<div class="sample-image-modal__backdrop" data-sample-image-close="1"></div>' +
      '<section class="sample-image-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="sample-image-modal-title">' +
        '<header class="sample-image-modal__header">' +
          '<h2 id="sample-image-modal-title" class="sample-image-modal__title">サンプル画像</h2>' +
          '<button type="button" class="sample-image-modal__close" data-sample-image-close="1" aria-label="閉じる">×</button>' +
        '</header>' +
        '<div class="sample-image-modal__stage">' +
          '<button type="button" class="sample-image-modal__arrow sample-image-modal__arrow--prev" aria-label="前の画像">‹</button>' +
          '<img class="sample-image-modal__main" src="" alt="">' +
          '<button type="button" class="sample-image-modal__arrow sample-image-modal__arrow--next" aria-label="次の画像">›</button>' +
          '<p class="sample-image-modal__status" role="status"></p>' +
        '</div>' +
        '<div class="sample-image-modal__thumbs" aria-label="サンプル画像一覧"></div>' +
      '</section>';
    document.body.appendChild(modal);
    mainImage = modal.querySelector('.sample-image-modal__main');
    thumbs = modal.querySelector('.sample-image-modal__thumbs');
    titleNode = modal.querySelector('.sample-image-modal__title');
    statusNode = modal.querySelector('.sample-image-modal__status');
    previousButton = modal.querySelector('.sample-image-modal__arrow--prev');
    nextButton = modal.querySelector('.sample-image-modal__arrow--next');

    mainImage.addEventListener('error', function () {
      statusNode.textContent = '画像を表示できませんでした。別の画像を選択してください。';
    });

    modal.addEventListener('click', function (event) {
      if (event.target.closest('[data-sample-image-close="1"]')) closeModal();
    });
    previousButton.addEventListener('click', function () { showImage(activeIndex - 1); });
    nextButton.addEventListener('click', function () { showImage(activeIndex + 1); });
  }

  function showImage(index) {
    if (!images.length) return;
    activeIndex = (index + images.length) % images.length;
    mainImage.src = images[activeIndex];
    mainImage.alt = 'サンプル画像 ' + (activeIndex + 1) + ' / ' + images.length;
    statusNode.textContent = (activeIndex + 1) + ' / ' + images.length;
    previousButton.hidden = images.length < 2;
    nextButton.hidden = images.length < 2;
    Array.prototype.forEach.call(thumbs.querySelectorAll('button'), function (button, buttonIndex) {
      button.classList.toggle('is-active', buttonIndex === activeIndex);
      button.setAttribute('aria-current', buttonIndex === activeIndex ? 'true' : 'false');
    });
  }

  function renderThumbs() {
    thumbs.innerHTML = '';
    images.forEach(function (url, index) {
      var button = document.createElement('button');
      var image = document.createElement('img');
      button.type = 'button';
      button.className = 'sample-image-modal__thumb';
      button.setAttribute('aria-label', '画像 ' + (index + 1) + ' を表示');
      image.src = url;
      image.alt = '';
      image.loading = 'lazy';
      button.appendChild(image);
      button.addEventListener('click', function () { showImage(index); });
      thumbs.appendChild(button);
    });
  }

  function openModal(trigger) {
    var url = trigger.dataset.sampleImagesUrl || '';
    if (!url) return;
    buildModal();
    returnFocus = trigger;
    savedScrollY = window.scrollY || window.pageYOffset || 0;
    images = [];
    thumbs.innerHTML = '';
    mainImage.removeAttribute('src');
    titleNode.textContent = trigger.dataset.sampleImagesTitle || 'サンプル画像';
    statusNode.textContent = '画像を読み込んでいます…';
    previousButton.hidden = true;
    nextButton.hidden = true;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('sample-image-modal-open');
    modal.querySelector('.sample-image-modal__close').focus();

    fetch(sampleJsonUrl(url), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    })
      .then(function (response) {
        if (!response.ok) throw new Error('sample image request failed');
        var contentType = response.headers.get('content-type') || '';
        if (contentType.toLowerCase().indexOf('application/json') === -1) {
          throw new Error('sample image response was not JSON');
        }
        return response.json();
      })
      .then(function (payload) {
        images = Array.isArray(payload.images)
          ? payload.images.filter(function (urlValue) { return /^https?:\/\//i.test(urlValue); })
          : [];
        titleNode.textContent = payload.title || trigger.dataset.sampleImagesTitle || 'サンプル画像';
        if (!images.length) {
          statusNode.textContent = '表示できるサンプル画像がありません。';
          return;
        }
        renderThumbs();
        showImage(0);
      })
      .catch(function () {
        statusNode.textContent = 'サンプル画像を読み込めませんでした。時間をおいてもう一度お試しください。';
      });
  }

  function closeModal() {
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('sample-image-modal-open');
    mainImage.removeAttribute('src');
    window.scrollTo(0, savedScrollY);
    if (returnFocus) returnFocus.focus();
  }

  function triggerFromEvent(event) {
    var target = event.target;
    return target && target.closest ? target.closest('.sample-image-trigger') : null;
  }

  function handleTriggerClick(event) {
    var trigger = triggerFromEvent(event);
    if (!trigger || trigger.disabled || !trigger.dataset.sampleImagesUrl) return;
    event.preventDefault();
    event.stopPropagation();
    openModal(trigger);
  }

  function initializeTriggers() {
    if (initialized) return;
    initialized = true;

    Array.prototype.forEach.call(document.querySelectorAll('.sample-image-trigger[data-sample-images-url]'), function (trigger) {
      trigger.addEventListener('click', handleTriggerClick);
      trigger.setAttribute('data-sample-image-modal-ready', 'true');
    });

    // Dynamically inserted cards are handled here; existing cards use their direct listener above.
    document.addEventListener('click', function (event) {
      var trigger = triggerFromEvent(event);
      if (!trigger || trigger.getAttribute('data-sample-image-modal-ready') === 'true') return;
      handleTriggerClick(event);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeTriggers);
  } else {
    initializeTriggers();
  }

  document.addEventListener('keydown', function (event) {
    if (!modal || !modal.classList.contains('is-open')) return;
    if (event.key === 'Escape') closeModal();
    if (event.key === 'ArrowLeft') showImage(activeIndex - 1);
    if (event.key === 'ArrowRight') showImage(activeIndex + 1);
  });
}());
