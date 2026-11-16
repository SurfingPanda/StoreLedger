import Swal from 'sweetalert2';

const alert = Swal.mixin({
  confirmButtonColor: '#1535b5',
  confirmButtonText: 'OK',
  heightAuto: false,
});

export function showSuccess(title = 'Success', text?: string) {
  return alert.fire({
    icon: 'success', title, text,
    showConfirmButton: false,
    timer: 1500,
    timerProgressBar: true,
    allowOutsideClick: false,
    allowEscapeKey: false,
  });
}

export function showError(text: string, title = 'Something went wrong') {
  return alert.fire({ icon: 'error', title, text });
}

export async function confirmAction(text: string, confirmButtonText = 'Yes, continue', title = 'Are you sure?') {
  const result = await alert.fire({
    icon: 'question', title, text,
    showCancelButton: true,
    cancelButtonText: 'Cancel',
    confirmButtonText,
    focusCancel: true,
  });
  return result.isConfirmed;
}
