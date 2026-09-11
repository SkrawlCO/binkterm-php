/* Noninteractive validation only; rules, decoding and status belong to upstream. */
#define _POSIX_C_SOURCE 200809L
#include "puzzles.h"
#include <string.h>
#include <stdarg.h>
#include <sys/resource.h>
#include <unistd.h>
#define LIMIT 102400
static unsigned char input[LIMIT + 1];
static size_t length, position, compared;
static bool equal = true;
void fatal(const char *fmt, ...) { exit(2); }
void frontend_default_colour(frontend *fe, float *out) { out[0]=out[1]=out[2]=0.8f; }
void get_random_seed(void **p, int *n) { *p=dupstr("validator"); *n=9; }
void activate_timer(frontend *fe) {}
void deactivate_timer(frontend *fe) {}
static bool reader(void *ctx, void *buf, int n) {
    if (n < 0 || (size_t)n > length-position) return false;
    memcpy(buf,input+position,n); position+=n; return true;
}
static void compare(void *ctx, const void *buf, int n) {
    if (n < 0 || (size_t)n > length-compared) { equal=false; return; }
    if (memcmp(input+compared,buf,n)) equal=false;
    compared+=n;
}
int main(void) {
    struct rlimit cpu={2,2}, memory={256*1024*1024,256*1024*1024};
    if (setrlimit(RLIMIT_CPU,&cpu) || setrlimit(RLIMIT_AS,&memory)) return 2;
    alarm(3);
    length=fread(input,1,sizeof(input),stdin);
    if (!length || length>LIMIT || ferror(stdin)) return 2;
    midend *me=midend_new(NULL,&thegame,NULL,NULL);
    if (midend_deserialise(me,reader,NULL)) return 2;
    char *id=midend_get_game_id(me);
    if (strcmp(id,"7x7:cBd0c1hBe2h1c0d0c")) return 2;
    midend_serialise(me,compare,NULL);
    if (!equal || compared!=length) return 2;
    printf("%d\n",midend_status(me));
    sfree(id); midend_free(me); return 0;
}
