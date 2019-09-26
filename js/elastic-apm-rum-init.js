/**
 * @file
 * Elastic APM RUM init.
 */

(function () {

  'use strict';

  /**
   * Get Cookie by name.
   *
   * @param {string} name
   *   The cookie name.
   *
   * @return {string|null}
   *   Returns cookie value or null.
   */
  var getCookie = function (name) {
    var v = document.cookie.match('(^|;) ?' + name + '=([^;]*)(;|$)');

    return v ? v[2] : null;
  };

  /**
   * User traceId if it's available in cookie.
   *
   * @type {string|null}
   */
  var traceId = getCookie('traceId');

  /**
   * Use Server URL if it's provided over cookie.
   *
   * @type {string}
   */
  var serverUrl = getCookie('serverUrl') || 'http://localhost:8200';

  /**
   * Init Elastic APM real user monitoring.
   */
  window.apm = window.elasticApm.init({
    serviceName: 'Thunder Browser Measurements',
    serverUrl: serverUrl,
    pageLoadTransactionName: window.location.pathname,
    pageLoadTraceId: traceId,
    flushInterval: 1
  });

  window.apm.addTags({
    branch: getCookie('branchTag'),
    test: getCookie('testTag')
  });

  window.elasticApm.addFilter(function (payload) {
    if (!payload.transactions) {
      return payload;
    }

    var spanId = getCookie('spanId');
    if (!spanId) {
      return payload;
    }

    // Keep browser transactions connected to NightwatchJS Test transactions.
    payload.transactions[0].parent_id = spanId;

    // Set context information on every span.
    payload.transactions.forEach(function (transaction) {
      transaction.spans.forEach(function (span) {
        span.context = span.context || {};
        span.context.tags = span.context.tags || {};

        // Add branch name and test name to span context tags.
        span.context.tags.branch = getCookie('branchTag');
        span.context.tags.test = getCookie('testTag');
      });
    });

    return payload;
  });

}());
