<?php include 'header.php';?>
<!-------------------- CODE Starts HERE -------------------->
<div class="container justify-content-center">
    <div class="card w-50 mx-auto my-4 shadow">
        <div class="card-header text-center bg-secondary bg-gradient py-3 h3 text-white">LOGIN</div>
        <div class="card-body bg-secondary-subtle">
            <form action="">
                <div class="mt-1">
                    <label class="form-label">Username</label>
                    <input type="text" id="username" class="form-control">
                </div>
                <div class="mt-3">
                    <label class="form-label">Password</label>
                    <input type="text" id="password" class="form-control">
                </div>

                <br />
                <input type="button" value="Submit" class="btn btn-success" onclick="login()" />
                &nbsp&nbsp&nbsp
                <input type="button" value="Register" class="btn btn-primary" onclick="toSignup()" />
                &nbsp&nbsp&nbsp&nbsp
                </div>
            </form>
        </div>
    </div>
</div>
<!-------------------- CODE ENDS HERE -------------------->
<?php include 'footer.php'; ?>