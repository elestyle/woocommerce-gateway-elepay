jQuery(function($) {
	'use strict';

	// Add secret visibility toggles.
	$('#woocommerce_elepay_secret_key').after(
		'<button class="wc-elepay-toggle-secret" style="height: 30px; margin-left: 2px; cursor: pointer"><span class="dashicons dashicons-visibility"></span></button>'
	);
	$('.wc-elepay-toggle-secret').on('click', function(event) {
		event.preventDefault();

		var $dashicon = $(this).closest('button').find('.dashicons');
		var $input = $(this).closest('tr').find('.input-text');
		var inputType = $input.attr('type');

		if ('text' === inputType) {
			$input.attr('type', 'password');
			$dashicon.removeClass('dashicons-hidden');
			$dashicon.addClass('dashicons-visibility');
		} else {
			$input.attr('type', 'text');
			$dashicon.removeClass('dashicons-visibility');
			$dashicon.addClass('dashicons-hidden');
		}
	});

	// Add Webhook url copy.
	$('#woocommerce_elepay_webhook').after(
		'<button class="wc-elepay-copy-btn" style="height: 30px; margin-left: 2px; cursor: pointer" data-clipboard-target="#woocommerce_elepay_webhook"><span class="dashicons dashicons-admin-page"></span></button>'
	);
	$('.wc-elepay-copy-btn').on('click', function(event) {
		event.preventDefault();
	});
	var clipboard = new ClipboardJS('.wc-elepay-copy-btn');
	clipboard.on('success',function(e){
		e.clearSelection();
		// console.info('Action:',e.action);
		// console.info('Text:',e.text);
		// console.info('Trigger:',e.trigger);
		showTooltip(e.trigger,'Copied!');
	});
	clipboard.on('error',function(e){
		// console.error('Action:',e.action);
		// console.error('Trigger:',e.trigger);
		showTooltip(e.trigger,fallbackMessage(e.action));
	});
	var btns = document.querySelectorAll('.wc-elepay-copy-btn');
	for (var i=0; i<btns.length; i++) {
		btns[i].addEventListener('mouseleave',clearTooltip);
		btns[i].addEventListener('blur',clearTooltip);
	}
	function clearTooltip(e){
		e.currentTarget.classList.remove('wc-elepay-tooltipped');
		e.currentTarget.classList.remove('wc-elepay-tooltipped-n');
		e.currentTarget.removeAttribute('aria-label');
	}
	function showTooltip(elem,msg){
		elem.classList.add('wc-elepay-tooltipped');
		elem.classList.add('wc-elepay-tooltipped-n');
		elem.setAttribute('aria-label',msg);
	}
	function fallbackMessage(action){
		var actionMsg='';
		var actionKey=(action==='cut'?'X':'C');
		if(/iPhone|iPad/i.test(navigator.userAgent)){
			actionMsg='No support :(';
		} else if(/Mac/i.test(navigator.userAgent)){
			actionMsg='Press ⌘-'+actionKey+' to '+action;
		} else{
			actionMsg='Press Ctrl-'+actionKey+' to '+action;
		}
		return actionMsg;
	}
});
