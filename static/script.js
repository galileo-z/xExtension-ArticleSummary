/**
 * Article Summary Extension - Frontend JavaScript
 * Article Summary Extension - 鍓嶇JavaScript
 */

// Initialize summarize buttons when DOM is loaded
// 褰揇OM鍔犺浇瀹屾垚鏃跺垵濮嬪寲鎬荤粨鎸夐挳
if (document.readyState && document.readyState !== 'loading') {
  configureSummarizeButtons();
} else {
  document.addEventListener('DOMContentLoaded', configureSummarizeButtons, false);
}

/**
 * Configure event listeners for summarize buttons
 * 涓烘€荤粨鎸夐挳閰嶇疆浜嬩欢鐩戝惉鍣?
 */
function configureSummarizeButtons() {
  var root = document.getElementById('global') || document.body;
  if (!root || root.dataset.oaiSummaryBound === 'true') {
    return;
  }

  root.dataset.oaiSummaryBound = 'true';
  initializeVisibleSummarizeButtons(root);

  root.addEventListener('click', function (e) {
    for (var target = e.target; target && target !== this; target = target.parentNode) {
      
      // Handle article header click to add text to summary button
      // 澶勭悊鏂囩珷鏍囬鐐瑰嚮锛屼负鎬荤粨鎸夐挳娣诲姞鏂囨湰
      if (target.matches && target.matches('.flux_header')) {
        syncSummaryButtonTextFromHeader(target);
      }

      // Handle summarize button click
      // 澶勭悊鎬荤粨鎸夐挳鐐瑰嚮
      if (target.matches && target.matches('.oai-summary-btn')) {
        e.preventDefault();
        e.stopPropagation();
        if (target.dataset.request) {
          ensureSummaryButtonText(target);
          summarizeButtonClick(target);
        }
        break;
      }
    }
  }, false);

  document.addEventListener('freshrss:openArticle', function (event) {
    initializeVisibleSummarizeButtons(event.target);
    window.setTimeout(function () {
      var threePanesView = document.getElementById('threepanesview');
      if (threePanesView) {
        initializeVisibleSummarizeButtons(threePanesView);
      }
    }, 0);
  });

  if (window.MutationObserver) {
    var observer = new MutationObserver(function (mutations) {
      mutations.forEach(function (mutation) {
        Array.prototype.forEach.call(mutation.addedNodes, function (node) {
          if (node.nodeType === 1) {
            initializeVisibleSummarizeButtons(node);
          }
        });
      });
    });

    observer.observe(root, {
      childList: true,
      subtree: true
    });
  }
}

function initializeVisibleSummarizeButtons(root) {
  if (!root.querySelectorAll) {
    return;
  }

  if (root.matches && root.matches('#threepanesview .oai-summary-btn, .flux.current .oai-summary-btn')) {
    ensureSummaryButtonText(root);
  }

  Array.prototype.forEach.call(
    root.querySelectorAll('#threepanesview .oai-summary-btn, .flux.current .oai-summary-btn'),
    ensureSummaryButtonText
  );
}

function ensureSummaryButtonText(button) {
  if (button && !button.textContent.trim() && button.dataset.summarizeText) {
    button.textContent = button.dataset.summarizeText;
  }
}

function syncSummaryButtonTextFromHeader(header) {
  var articleBody = header.nextElementSibling;
  var button = articleBody ? articleBody.querySelector('.oai-summary-btn') : null;

  if (!button) {
    var article = header.closest ? header.closest('.flux, article, .entry') : null;
    button = article ? article.querySelector('.oai-summary-btn') : null;
  }

  ensureSummaryButtonText(button);
}

/**
 * Set the state of the AI summary component
 * 璁剧疆AI鎬荤粨缁勪欢鐨勭姸鎬?
 * 
 * @param {HTMLElement} container - The summary container element
 * @param {number} statusType - Status type: 1=loading, 2=error, 0=success
 * @param {string} statusMsg - Status message to display
 * @param {string} summaryText - Summary text to display when completed
 */
function setOaiState(container, statusType, statusMsg, summaryText) {
  const button = container.querySelector('.oai-summary-btn');
  const content = container.querySelector('.oai-summary-content');

  if (!button || !content) {
    return;
  }
  
  // Set different states based on statusType
  // 鏍规嵁statusType璁剧疆涓嶅悓鐘舵€?
  if (statusType === 1) {
    // Loading state
    // 鍔犺浇鐘舵€?
    container.classList.add('oai-loading');
    container.classList.remove('oai-error');
    content.textContent = statusMsg;
    button.disabled = true;
  } else if (statusType === 2) {
    // Error state
    // 閿欒鐘舵€?
    container.classList.remove('oai-loading');
    container.classList.add('oai-error');
    content.textContent = statusMsg;
    button.disabled = false;
  } else {
    // Success state
    // 鎴愬姛鐘舵€?
    container.classList.remove('oai-loading');
    container.classList.remove('oai-error');
    if (statusMsg === 'finish'){
      button.disabled = false;
    }
  }

  // Update content with summary text if provided
  // Note: summaryText from marked.parse() is already HTML, no need to convert newlines
  if (summaryText) {
    content.innerHTML = summaryText;
  }
}

/**
 * Handle summarize button click event
 * 澶勭悊鎬荤粨鎸夐挳鐐瑰嚮浜嬩欢
 * 
 * @param {HTMLElement} target - The clicked button element
 */
async function summarizeButtonClick(target) {
  var container = target.closest ? target.closest('.oai-summary-wrap') : target.parentNode;
  
  // Prevent multiple requests while loading
  // 鍔犺浇鏃堕槻姝㈠娆¤姹?
  if (!container || container.classList.contains('oai-loading')) {
    return;
  }

  // Set loading state
  // 璁剧疆鍔犺浇鐘舵€?
  const loadingText = target.dataset.loadingText || 'Loading...';
  setOaiState(container, 1, loadingText, null);

  // Get the request URL and prepare data
  // 鑾峰彇璇锋眰URL骞跺噯澶囨暟鎹?
  var url = decodeHtmlEntities(target.dataset.request || '');
  var data = {
    ajax: true,
    _csrf: context.csrf
  };

  try {
    // Send request to PHP backend
    // 鍚慞HP鍚庣鍙戦€佽姹?
    const response = await axios.post(url, data, {
      headers: {
        'Content-Type': 'application/json'
      }
    });

    const xresp = response.data;
    console.log(xresp);

    // Check if response is valid
    // 妫€鏌ュ搷搴旀槸鍚︽湁鏁?
    if (response.status !== 200 || !xresp || !xresp.response || !xresp.response.data) {
      const requestFailedText = target.dataset.requestFailedText || 'Request Failed';
      throw new Error(requestFailedText);
    }

    // Handle error response
    // 澶勭悊閿欒鍝嶅簲
    if (xresp.response.error) {
      setOaiState(container, 2, xresp.response.data, null);
    } else {
      const summaryText = String(xresp.response.data || '');
      const summaryHtml = window.marked && window.marked.parse
        ? window.marked.parse(summaryText)
        : escapeHtml(summaryText).replace(/\n/g, '<br>');
      setOaiState(container, 0, 'finish', summaryHtml);
    }
  } catch (error) {
    console.error(error);
    // Show more specific error message
    // 鏄剧ず鏇村叿浣撶殑閿欒淇℃伅
    const errorMsg = freshRssRequestErrorMessage(error, url);
    setOaiState(container, 2, errorMsg, null);
  }
}

function decodeHtmlEntities(value) {
  var textarea = document.createElement('textarea');
  var decoded = value;

  for (var i = 0; i < 3; i++) {
    textarea.innerHTML = decoded;
    if (textarea.value === decoded) {
      break;
    }
    decoded = textarea.value;
  }

  return decoded;
}

function freshRssRequestErrorMessage(error, requestUrl) {
  if (error.response) {
    return 'FreshRSS ArticleSummary endpoint returned HTTP ' + error.response.status + '\n' + requestUrl;
  }

  return error.message || 'Request Failed';
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}
