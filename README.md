# Moodle Video Quiz (mod_videoquiz)

Video Quiz turns a video into an interactive assessment. Teachers add questions at specific timeline positions. The
player pauses automatically, collects the learner answer, provides feedback, tracks watched segments, resumes playback,
calculates a grade and integrates with Moodle completion and the gradebook.

Supported question types: multiple choice, true/false, short answer and multiple answers. Questions can be mandatory,
allow one or multiple attempts, have correct/incorrect feedback, configurable points and optionally allow later changes.

Supported video sources: uploaded video, direct video URL, YouTube and Vimeo. Uploaded and direct HTML5 video can use
uploaded WebVTT caption files and an uploaded poster.

Grading modes: questions only, watched percentage only, or a configurable combination of questions and watched
percentage. Completion can require a watched percentage, all mandatory questions and/or the configured minimum grade.

The tracking model records actually played segments rather than treating a seek as watched content. Progress, last
position, unique watched time, attempts and scores are stored per learner.

This plugin was designed using concepts and reusable patterns from `moodle-mod_videoprogress` by Eduardo Kraus,
especially player configuration, resume behavior, watched-segment tracking, gradebook integration and completion
concepts.

## Installation

Copy the `videoquiz` directory to `mod/videoquiz` and visit Site administration > Notifications.

## License

GNU GPL v3 or later.
