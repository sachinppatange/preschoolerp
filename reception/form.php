<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Student Admission Form</title>

<!-- Bootstrap 5 -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
    body{background:#f4f6f9;}
    .card{margin-bottom:20px;}
    h5{background:#0d6efd;color:white;padding:8px;border-radius:5px;}
</style>
</head>

<body>

<div class="container my-4">

    <h2 class="text-center mb-4">Student Admission Form</h2>

<form>

<!-- Basic Info -->
<div class="card p-3">
<div class="row g-3">

<div class="col-md-4">
<label class="form-label">School ID (optional)</label>
<select class="form-select">
<option>Select School</option>
<option>ABC School</option>
<option>Pioneer Play School</option>
</select>
</div>

<div class="col-md-4">
<label class="form-label">Location</label>
<input type="text" class="form-control">
</div>

<div class="col-md-4">
<label class="form-label">Form No.</label>
<input type="text" id="formNo" class="form-control" readonly>
</div>

<div class="col-md-12">
<label class="form-label">Admission Seeking In</label><br>
<div class="form-check form-check-inline">
<input class="form-check-input" type="checkbox"> Play Group
</div>
<div class="form-check form-check-inline">
<input class="form-check-input" type="checkbox"> Nursery
</div>
<div class="form-check form-check-inline">
<input class="form-check-input" type="checkbox"> L.K.G.
</div>
<div class="form-check form-check-inline">
<input class="form-check-input" type="checkbox"> U.K.G.
</div>
</div>

</div>
</div>

<!-- Student Personal Details -->
<div class="card p-3">
<h5>Student's Personal Details</h5>
<div class="row g-3">

<div class="col-md-4"><input class="form-control" placeholder="First Name"></div>
<div class="col-md-4"><input class="form-control" placeholder="Middle Name"></div>
<div class="col-md-4"><input class="form-control" placeholder="Last Name"></div>

<div class="col-md-4">
<label>Date of Birth</label>
<input type="date" class="form-control">
</div>

<div class="col-md-4">
<label>Gender</label><br>
<input type="radio" name="gender"> Male
<input type="radio" name="gender" class="ms-3"> Female
</div>

<div class="col-md-4"><input class="form-control" placeholder="Place of Birth"></div>
<div class="col-md-4"><input class="form-control" placeholder="Nationality"></div>
<div class="col-md-4"><input class="form-control" placeholder="Caste"></div>
<div class="col-md-4"><input class="form-control" placeholder="Languages Known"></div>

</div>
</div>

<!-- Address -->
<div class="card p-3">
<h5>Residential Address & Family Information</h5>
<div class="row g-3">
<div class="col-md-12"><input class="form-control" placeholder="Address"></div>
<div class="col-md-3"><input class="form-control" placeholder="City"></div>
<div class="col-md-3"><input class="form-control" placeholder="State"></div>
<div class="col-md-3"><input class="form-control" placeholder="Country"></div>
<div class="col-md-3"><input class="form-control" placeholder="PIN Code"></div>
</div>
</div>

<!-- Father -->
<div class="card p-3">
<h5>Father Details</h5>
<div class="row g-3">
<div class="col-md-4"><input class="form-control" placeholder="First Name"></div>
<div class="col-md-4"><input class="form-control" placeholder="Middle Name"></div>
<div class="col-md-4"><input class="form-control" placeholder="Last Name"></div>
<div class="col-md-4"><input type="email" class="form-control" placeholder="Email"></div>
<div class="col-md-4"><input class="form-control" placeholder="Qualification"></div>
<div class="col-md-4"><input class="form-control" placeholder="Profession"></div>
<div class="col-md-4"><input class="form-control" placeholder="Designation"></div>
<div class="col-md-4"><input class="form-control" placeholder="Phone"></div>
<div class="col-md-4"><input class="form-control" placeholder="WhatsApp"></div>

<div class="col-md-4">
<select class="form-select">
<option>Status</option>
<option>Active</option>
<option>Inactive</option>
</select>
</div>

<div class="col-md-12">
<textarea class="form-control" placeholder="Meta / Notes"></textarea>
</div>
</div>
</div>

<!-- Mother -->
<div class="card p-3">
<h5>Mother Details</h5>
<div class="row g-3">
<div class="col-md-4"><input class="form-control" placeholder="First Name"></div>
<div class="col-md-4"><input class="form-control" placeholder="Middle Name"></div>
<div class="col-md-4"><input class="form-control" placeholder="Last Name"></div>
<div class="col-md-4"><input type="email" class="form-control" placeholder="Email"></div>
<div class="col-md-4"><input class="form-control" placeholder="Qualification"></div>
<div class="col-md-4"><input class="form-control" placeholder="Profession"></div>
<div class="col-md-4"><input class="form-control" placeholder="Designation"></div>
<div class="col-md-4"><input class="form-control" placeholder="Phone"></div>
</div>
</div>

<!-- Guardian -->
<div class="card p-3">
<h5>Guardian (Emergency)</h5>
<div class="row g-3">
<div class="col-md-4"><input class="form-control" placeholder="Full Name"></div>
<div class="col-md-4"><input type="email" class="form-control" placeholder="Email"></div>
<div class="col-md-4"><input class="form-control" placeholder="Relation"></div>
<div class="col-md-4"><input class="form-control" placeholder="Phone"></div>
</div>
</div>

<!-- Education -->
<div class="card p-3">
<h5>Educational Background</h5>
<input class="form-control" placeholder="Previous School">
</div>

<!-- Medical -->
<div class="card p-3">
<h5>Medical Information</h5>
<div class="row g-3">
<div class="col-md-3"><input class="form-control" placeholder="Allergies"></div>
<div class="col-md-3"><input class="form-control" placeholder="Health Conditions"></div>
<div class="col-md-3"><input class="form-control" placeholder="Medications"></div>
<div class="col-md-3"><input class="form-control" placeholder="Immunization"></div>
</div>
</div>

<!-- Others -->
<div class="card p-3">
<h5>Additional Details</h5>
<div class="row g-3">
<div class="col-md-6"><input class="form-control" placeholder="Sibling 1"></div>
<div class="col-md-6"><input class="form-control" placeholder="Sibling 2"></div>
<div class="col-md-12"><textarea class="form-control" placeholder="Additional Info"></textarea></div>
<div class="col-md-4"><input type="file" class="form-control"></div>
<div class="col-md-4"><input class="form-control" placeholder="Date"></div>
<div class="col-md-4"><input class="form-control" placeholder="Signature"></div>
</div>
</div>

<!-- Office Use -->
<div class="card p-3">
<h5>Office Use Only</h5>
<div class="row g-3">
<div class="col-md-4"><input class="form-control" placeholder="Total Fees"></div>
<div class="col-md-2"><input class="form-control" placeholder="Installment 1"></div>
<div class="col-md-2"><input class="form-control" placeholder="Installment 2"></div>
<div class="col-md-2"><input class="form-control" placeholder="Installment 3"></div>
<div class="col-md-4"><input class="form-control" placeholder="Remark"></div>
<div class="col-md-4"><input class="form-control" placeholder="Stamp"></div>
</div>
</div>

<div class="text-center mb-5">
<button class="btn btn-primary btn-lg">Submit Admission Form</button>
</div>

</form>
</div>

<script>
// Auto Form Number Generator
document.getElementById("formNo").value = "FORM-" + Date.now();
</script>

</body>
</html>