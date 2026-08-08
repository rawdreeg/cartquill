import { AiRewrite } from './AiRewrite';

/*
 * Entry point for the AI add-on's builder bundle. It fills the builder's
 * `emailCopyAssist` slot so an email step gains a "Rewrite with AI" control.
 *
 * This bundle is built and enqueued only by the AI add-on; the plugin's own
 * builder renders nothing where the slot is unfilled. See ../slots.js.
 */
window.cartquillBuilderSlots = window.cartquillBuilderSlots || {};
window.cartquillBuilderSlots.emailCopyAssist = AiRewrite;
