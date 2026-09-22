<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace SoundChex\PlaylistPorter\Services;

use RuntimeException;

/**
 * A streaming service refused a request for a reason the operator can act on.
 *
 * Distinct from a transport failure or a bug: the message is written for the
 * person at the screen and is safe to show them, so the UI can surface it
 * instead of a bare HTTP status (S-323).
 */
class PlaylistSourceException extends RuntimeException {}
