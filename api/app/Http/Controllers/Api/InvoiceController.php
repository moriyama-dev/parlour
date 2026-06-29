<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Project;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Project $project)
    {
        $invoices = $project->invoices()->with('items')->get();

        return InvoiceResource::collection($invoices);
    }

    public function store(Request $request, Project $project)
    {
        if ($request->user()->role !== 'developer') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'issued_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date'],
            'subtotal' => ['required', 'numeric'],
            'tax_rate' => ['nullable', 'numeric'],
            'tax_amount' => ['nullable', 'numeric'],
            'total' => ['required', 'numeric'],
            'notes' => ['nullable', 'string'],
        ]);

        // Auto-generate invoice number INV-YYYY-NNN
        $year = now()->year;
        $count = Invoice::whereYear('created_at', $year)->count() + 1;
        $invoiceNumber = 'INV-' . $year . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);

        $invoice = $project->invoices()->create([
            ...$validated,
            'company_id' => $project->company_id,
            'invoice_number' => $invoiceNumber,
            'status' => 'draft',
        ]);

        return new InvoiceResource($invoice->load('items'));
    }

    public function show(Project $project, Invoice $invoice)
    {
        return new InvoiceResource($invoice->load('items'));
    }

    public function update(Request $request, Project $project, Invoice $invoice)
    {
        if ($invoice->status !== 'draft') {
            return response()->json(['message' => 'Only draft invoices can be updated'], 422);
        }

        $validated = $request->validate([
            'issued_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date'],
            'subtotal' => ['sometimes', 'numeric'],
            'tax_rate' => ['nullable', 'numeric'],
            'tax_amount' => ['nullable', 'numeric'],
            'total' => ['sometimes', 'numeric'],
            'notes' => ['nullable', 'string'],
        ]);

        $invoice->update($validated);

        return new InvoiceResource($invoice->load('items'));
    }

    public function send(Project $project, Invoice $invoice)
    {
        $invoice->update(['status' => 'sent']);

        // Notify company users
        $companyUsers = $project->company->users;
        foreach ($companyUsers as $user) {
            Notification::create([
                'user_id' => $user->id,
                'type' => 'invoice_sent',
                'data' => ['invoice_id' => $invoice->id, 'invoice_number' => $invoice->invoice_number, 'project_id' => $project->id],
            ]);
        }

        return new InvoiceResource($invoice->load('items'));
    }

    public function markPaid(Project $project, Invoice $invoice)
    {
        $invoice->update(['status' => 'paid']);

        return new InvoiceResource($invoice->load('items'));
    }
}
