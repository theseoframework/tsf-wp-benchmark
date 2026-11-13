// Keep large tables and JSON text out of the active DOM until requested.
document.addEventListener( 'toggle', ( event ) => {
	const panel = event.target;
	if ( ! ( panel instanceof HTMLDetailsElement ) || ! panel.isConnected ) return;
	const payload = panel.querySelector( ':scope > script[data-fragment]' );
	const slot = panel.querySelector( ':scope > .panel-slot' );
	if ( ! payload || ! slot ) return;
	if ( ! panel.open ) {
		slot.replaceChildren();
		delete panel.dataset.loaded;
		return;
	}
	if ( panel.dataset.loaded ) return;
	try {
		// Only the server's escaped HTML is stored here; untrusted labels are never executable markup.
		slot.innerHTML = JSON.parse( payload.textContent );
		panel.dataset.loaded = 'true';
	} catch {
		slot.textContent = 'Details could not be loaded. Use the full expanded report or saved analysis JSON.';
	}
}, true );
