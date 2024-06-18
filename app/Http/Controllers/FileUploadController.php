<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessExcelFile;
use App\Models\Row;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class FileUploadController extends Controller
{
    public function upload(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:xlsx'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 400);
        }

        $file = $request->file('file');
        $filePath = $file->store('uploads');

        ProcessExcelFile::dispatch(Storage::path($filePath));;

        return response()->json(['message' => 'File uploaded successfully'], 200);
    }

    public function index()
    {
        $rows = Row::select('date', DB::raw('GROUP_CONCAT(id) AS ids'), DB::raw('GROUP_CONCAT(name) AS names'))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[$row->date] = [
                'ids' => explode(',', $row->ids),
                'names' => explode(',', $row->names)
            ];
        }

        return response()->json($result);
    }
}
