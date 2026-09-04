<?php

namespace Devletes\Sidekick\Contracts;

/**
 * A tool that is handed to the model directly even in catalog mode, instead of being listed by ListTools.
 *
 * Reserved for the few tools whose call is read back afterwards rather than just answered — PresentActions'
 * buttons are re-resolved from its persisted arguments at render time, which only works while the call is
 * recorded under its own name. A host can mark a hot-path tool this way too, at the cost of its definition
 * riding along in every request.
 */
interface AlwaysOffered {}
