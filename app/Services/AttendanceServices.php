<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\ClassStudent;
use App\Models\InviteLessonMail;
use App\Models\Lesson;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AttendanceServices
{
    public function getListClass()
    {
        $classrooms = Classroom::select(
            'classrooms.id',
            DB::raw('subjects.name as subject_name'),
            DB::raw('subjects.code as subject_code'),
        )
            ->whereHas('semester', function ($q) {
                return $q->where('end_time', '>', now())
                    ->where('start_time', '<', now());
            })
            ->whereHas('lessons', function ($q) {
                return $q->where('teacher_email', Auth::user()->email)
                    ->orWhere('tutor_email', Auth::user()->email);
            })
            ->join('subjects', 'subjects.id', '=', 'classrooms.subject_id')
            ->withCount('classStudents')
            ->withCount('lessons')
            ->get();

        return ($classrooms);
    }

    public function getDataByLesson(Lesson $lesson)
    {
        $attendHistoryMap = [];
        $sendMailHistoryMap = [];

        $students = ClassStudent::select(
            DB::raw('users.name as student_name'),
            DB::raw('users.code as student_code'),
            'class_students.student_email',
            'class_students.is_warning',
            'class_students.is_joined',
        )
            ->where('classroom_id', $lesson->classroom_id)
            ->where('is_warning', true)
            ->leftJoin('users', 'users.email', 'class_students.student_email')
            ->orderBy('class_students.student_email', 'ASC')
            ->get();

        if ($lesson && $lesson->attended) {
            $attendHistory = Attendance::where('lesson_id', $lesson->id)->get();
            foreach ($attendHistory as $x) {
                $attendHistoryMap[$x->student_email] = $x->toArray();
            }
            
            $sendMailHistory = InviteLessonMail::where('lesson_id', $lesson->id)->get();
            foreach ($sendMailHistory as $x) {
                $sendMailHistoryMap[$x->student_email] = 1;
            }
        }

        foreach ($students as $i => $student) {
            $checkAttended = $lesson && $lesson->attended && array_key_exists($student->student_email, $attendHistoryMap);
            $student->status = $checkAttended ? $attendHistoryMap[$student->student_email]['status'] : 1;
            $student->note = $checkAttended ? $attendHistoryMap[$student->student_email]['note'] : '';

            $checkSentMail = $lesson && $lesson->attended && array_key_exists($student->student_email, $sendMailHistoryMap);
            $student->is_sent_mail = $checkSentMail ? 1 : 0;

            $students[$i] = $student;
        }

        return $students;
    }

    public function update($lessonId, $data)
    {
        $lesson = Lesson::where('id', $lessonId)
            ->where('start_time', '<=', now())
            ->where('end_time', '>=', now())
            ->first();

        if (!$lesson) {
            return response([
                'message' => 'Chưa đến thời gian điểm danh'
            ], 400);
        }

        $emails = array_map(fn ($x) => $x['student_email'], $data);

        $presentEmails = array_filter(array_map(fn ($x) => $x['status'] == Attendance::STATUS_PRESENT && in_array($x['student_email'], $emails) ? $x['student_email'] : null, $data));
        $absentEmails = array_filter(array_map(fn ($x) => $x['status'] == Attendance::STATUS_ABSENT && in_array($x['student_email'], $emails) ? $x['student_email'] : null, $data));

        if (!$lesson->attended) {
            $array_attendances = [];

            $studentsInClass = ClassStudent::where('classroom_id', $lesson->classroom_id)->get();
            $studentsEmails = array_map(fn ($x) => $x['student_email'], $studentsInClass->toArray());

            foreach ($studentsEmails as $email) {
                $array_attendances[] = [
                    'lesson_id' => $lesson->id,
                    'student_email' => $email,
                ];
            }
            Attendance::insert($array_attendances);
        }

        Attendance::where('lesson_id', $lessonId)->whereIn('student_email', $presentEmails)->update(["status" => Attendance::STATUS_PRESENT]);
        Attendance::where('lesson_id', $lessonId)->whereIn('student_email', $absentEmails)->update(["status" => Attendance::STATUS_ABSENT]);
        $lesson->attended = true;
        $lesson->save();

        return response([
            'message' => 'Cập nhật điểm danh thành công'
        ], 200);
    }
    
    public function studentCheckin($lesson)
    {
        if ($lesson->start_time > now()) {
            return response([
                'message' => 'Buổi học chưa diễn ra'
            ], 400);

        } elseif ($lesson->end_time < now()) {
            return response([
                'message' => 'Đã quá thời gian checkin'
            ], 400);
        }

        $checkAttendance = Attendance::where('lesson_id', $lesson->id)
            ->where('student_email', Auth::user()->email)
            ->exists();

        if ($checkAttendance) {
            return response([
                'message' => 'Bạn đã checkin trong buổi học này'
            ], 400);
        }

        $checkUserInClass = ClassStudent::where('student_email', Auth::user()->email)
            ->where('classroom_id', $lesson->classroom_id)
            ->exists();

        if (!$checkUserInClass) {
            return response([
                'message' => 'Bạn không có trong danh sách lớp này'
            ], 400);
        }

        Attendance::create([
            'student_email' => Auth::user()->email,
            'lesson_id' => $lesson->id,
            'status' => Attendance::STATUS_PRESENT
        ]);

        return response([
            'message' => 'Checkin thành công'
        ], 200);
    }
}
