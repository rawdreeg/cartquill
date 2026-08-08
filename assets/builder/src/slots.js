/*
 * A tiny component registry for the builder.
 *
 * CartQuill registers nothing here — every slot is empty in the plugin as shipped,
 * and the builder simply renders nothing where a slot is unfilled. It exists so a
 * separately-distributed extension can enqueue its own bundle and drop a React
 * component into a named position without the builder having to know about it.
 *
 * The registry lives on `window` rather than in module scope because an extension
 * is compiled as its own bundle and cannot import from this one. To fill a slot,
 * an extension assigns before the builder mounts:
 *
 *     window.cartquillBuilderSlots = window.cartquillBuilderSlots || {};
 *     window.cartquillBuilderSlots.emailCopyAssist = MyComponent;
 *
 * The builder mounts on DOMContentLoaded, so any enqueued script has already run by
 * then regardless of the order WordPress printed them in.
 */

/**
 * The component registered for a slot, or null when nothing fills it.
 *
 * @param {string} name the slot name
 * @return {Function|null} the registered component, if any
 */
export function getSlot( name ) {
	const registry = window.cartquillBuilderSlots;
	return ( registry && registry[ name ] ) || null;
}
