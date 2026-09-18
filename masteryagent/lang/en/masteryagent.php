<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Strings for mod_masteryagent.
 *
 * @package    mod_masteryagent
 * @copyright  2026 MCU-NPS AI Learning Initiatives
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Mastery agent';
$string['modulename'] = 'Mastery agent';
$string['modulenameplural'] = 'Mastery agents';
$string['modulename_help'] = 'The mastery agent assesses a learner through a short, bounded conversation rather than a multiple-choice quiz.

Load one lesson or a whole sequence of lessons from a question-set JSON file. The agent puts each lesson question to the learner, probes where the evidence is thin, challenges misconceptions, and moves straight on to the next lesson when one closes. At the end it reports a score and written feedback for every lesson, plus an overall judgement, and writes the total to the gradebook.

All model requests go through Moodle\'s AI subsystem, so the site\'s configured provider, rate limits and logging apply.';
$string['pluginadministration'] = 'Mastery agent administration';
$string['masteryagent:addinstance'] = 'Add a new mastery agent activity';
$string['masteryagent:view'] = 'View mastery agent activity';
$string['masteryagent:attempt'] = 'Take a mastery assessment';
$string['masteryagent:viewreports'] = 'View mastery assessment transcripts and scores';

// Form.
$string['activityname'] = 'Activity name';
$string['sourceheader'] = 'Lesson source';
$string['lessonfile'] = 'Question set file (JSON)';
$string['lessonfile_help'] = 'Upload the course question-set JSON. The file may contain every lesson; which of them this activity assesses is set below. Re-upload a newer file at any time to update the questions and rubrics without touching anything else.';
$string['lessonkeys'] = 'Lessons to assess';
$string['lessonkeys_help'] = 'Which records in the file this activity assesses, as a comma separated list of lesson or question IDs, for example L01 or L01,L02,L03. The agent works through them in the order given and moves on by itself as each one closes.

Leave this blank to assess every lesson in the file in one sitting.';
$string['currentlesson'] = 'Currently loaded';
$string['lessoncount'] = '{$a} lessons, assessed in order:';
$string['nolessonloaded'] = 'No lesson has been loaded into this activity yet. Edit the settings and upload a question-set file.';
$string['sourcestatus'] = 'Source rubric status: {$a}';
$string['assessmentheader'] = 'Assessment settings';
$string['maxturns'] = 'Maximum replies per lesson';
$string['maxturns_help'] = 'How many replies the learner may give on each lesson before the agent closes it and moves on. The agent may close a lesson sooner if mastery is demonstrated.';
$string['maxgrade'] = 'Maximum score per lesson';
$string['threshold'] = 'Mastery threshold per lesson';
$string['threshold_help'] = 'The per-lesson score at or above which mastery is considered demonstrated. The gradebook total is this activity\'s maximum score multiplied by the number of lessons.';
$string['allowretry'] = 'Allow repeat attempts';
$string['allowretry_help'] = 'Let the learner start a fresh conversation after one has been scored. The gradebook keeps the highest score.';
$string['provisional'] = 'Mark grades as AI-provisional';
$string['provisional_help'] = 'Flags every released grade as an AI judgement awaiting subject matter expert validation. Leave this on until the rubric has been validated.';

// Activity.
$string['introblurb'] = 'This is a short assessment conversation, not a quiz. Answer in your own words. You have up to {$a} replies, and it may end sooner once you have shown what the lesson requires.';
$string['introblurbmulti'] = 'This is an assessment conversation covering {$a->lessons} lessons, not a quiz. Answer in your own words. Each lesson allows up to {$a->turns} replies and may close sooner once you have shown what it requires; the evaluator moves on to the next lesson by itself. You can stop and come back, and your place is kept.';
$string['begin'] = 'Begin assessment';
$string['progress'] = 'Lesson {$a->position} of {$a->total}:';
$string['lessoncomplete'] = 'That closes lesson {$a->done} of {$a->total}. Next: {$a->next}.';
$string['lessonsinthisactivity'] = 'Lessons assessed by this activity ({$a})';
$string['lessonsmastered'] = 'Lessons at or above the mastery threshold: {$a->mastered} of {$a->total}.';
$string['perlessonscores'] = 'Per-lesson scores';
$string['lesson'] = 'Lesson';
$string['lessonsdone'] = 'Lessons closed';
$string['lessonquestion'] = 'Assessment question';
$string['roleagent'] = 'Evaluator';
$string['rolestudent'] = 'You';
$string['turnsleft'] = 'Replies remaining: {$a}';
$string['replyplaceholder'] = 'Write your answer here';
$string['sendreply'] = 'Send reply';
$string['finishnow'] = 'End and score now';
$string['tryagain'] = 'Start a new attempt';
$string['scoreline'] = 'Score: {$a->score} of {$a->max}';
$string['verdictmet'] = 'Mastery threshold met.';
$string['verdictnotmet'] = 'Mastery threshold not yet met ({$a} required).';
$string['provisionalbanner'] = 'AI-provisional assessment, pending subject matter expert validation.';
$string['dimensionbreakdown'] = 'Mastery dimensions';
$string['dimension'] = 'Dimension';
$string['verdict'] = 'Judgement';
$string['comment'] = 'Comment';
$string['verdictmetshort'] = 'Met';
$string['verdictpartial'] = 'Partial';
$string['verdictnotmetshort'] = 'Not met';
$string['nextstep'] = 'Next step:';

// Report.
$string['viewreport'] = 'View attempts';
$string['viewtranscript'] = 'View transcript';
$string['backtoreport'] = 'Back to attempts';
$string['noattempts'] = 'Nobody has attempted this assessment yet.';
$string['status'] = 'Status';
$string['statusinprogress'] = 'In progress';
$string['statusfinished'] = 'Finished';
$string['turnsused'] = 'Replies used';
$string['score'] = 'Score';
$string['finished'] = 'Finished';
$string['finalfeedback'] = 'Final feedback';
$string['evidenceledger'] = 'Evidence ledger';
$string['rubricheading'] = 'Rubric behind this assessment';
$string['strongevidence'] = 'Strong evidence';
$string['partialevidence'] = 'Partial evidence';
$string['misconceptions'] = 'Misconceptions and red flags';
$string['insufficientevidence'] = 'Insufficient evidence conditions';
$string['validationnotes'] = 'Validation notes from the question set';
$string['sourceevidence'] = 'Source evidence';
$string['sourcelink'] = 'open source';

// Errors.
$string['errorbadjson'] = 'That file is not a readable question set. Expected JSON containing a "questions" array.';
$string['errorfilerequired'] = 'Upload a question-set JSON file.';
$string['errorlessonnotfound'] = 'No lesson matching "{$a}" was found in the uploaded file.';
$string['errorlessonrequired'] = 'One or more of those lessons is not in the uploaded file. Available: {$a}';
$string['errorthreshold'] = 'The mastery threshold cannot be higher than the maximum score.';
$string['errormaxgrade'] = 'The maximum score must be at least 1.';
$string['errorprovider'] = 'The AI provider could not be reached: {$a} Please try again. Your draft remains in the reply box.';
$string['errorempty'] = 'The AI provider returned an empty response. Please try again. Your draft remains in the reply box.';
$string['errorbadresponse'] = 'The AI provider returned something this activity could not read. Please try again. Your draft remains in the reply box.';

// AJAX conversation.
$string['yourreply'] = 'Your reply';
$string['processing'] = 'The evaluator is working. Please wait…';
$string['conversationupdated'] = 'Conversation updated.';
$string['ajaxerror'] = 'The request could not be completed. Your draft is still here. Check your connection and try again.';
$string['conversationbusy'] = 'This conversation is already processing a request. Please wait and try again.';
$string['conversationchanged'] = 'The conversation has changed, possibly in another tab or after a delayed request. The latest saved conversation is shown. Review it before sending another reply.';
$string['attemptnotavailable'] = 'This action is not available for the current attempt.';
$string['replyrequired'] = 'Enter a reply before sending it.';
$string['replytoolong'] = 'Your reply must contain no more than {$a} characters.';
$string['recovereddraft'] = 'Your unsent draft (copy it before starting another attempt)';

// Student learning plan.
$string['learningstrengths'] = 'What you understand';
$string['learninggaps'] = 'What needs work';
$string['learningnextstep'] = 'What to do next';
$string['learningstrengthsempty'] = 'No specific strengths were recorded for this lesson.';
$string['learninggapsempty'] = 'No specific gaps were recorded. This does not mean every learning objective was assessed.';
$string['learningnextstepempty'] = 'Review the feedback and course readings, then explain the topic again in your own words.';
$string['learningreadings'] = 'Course readings';
$string['learningreadingshelp'] = 'Use these lesson references to revisit the feedback above. Page and section references are shown where available.';
$string['learningreadingsempty'] = 'No reading references were saved with this result. Check the lesson materials in your course.';
$string['learningbreakdown'] = 'Feedback on assessed skills';
$string['learningskill'] = 'Assessed skill';
$string['learningdimensionfallback'] = 'Assessed skill {$a}';
$string['learningverdictunknown'] = 'Not recorded';
